# Bagisto Helm Chart Architecture

This document explains the containerized architecture of the Bagisto e-commerce platform deployment using Kubernetes and Helm, specifically designed for multi-architecture support (ARM64/AMD64) and production scalability.

## Overview

The deployment uses a **sidecar pattern** with an **init container** to achieve separation of concerns, proper file sharing, and production-grade web serving capabilities.

## Container Architecture

### Pod Structure
```
┌─────────────────────────────────────────────────────────────┐
│                           Pod                               │
│                                                             │
│  ┌─────────────────┐                                        │
│  │  Init Container │  (runs first, completes, then exits)   │
│  │ copy-public-    │                                        │
│  │     files       │                                        │
│  └─────────────────┘                                        │
│                                                             │
│  ┌─────────────────┐    ┌─────────────────┐                 │
│  │ Main Container  │    │ Sidecar Container│                │
│  │    bagisto      │    │     nginx       │                 │
│  │   (PHP-FPM)     │    │  (Web Server)    │                │
│  │   Port: 9000    │    │   Port: 80      │                 │
│  └─────────────────┘    └─────────────────┘                 │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Container Responsibilities

### 1. Init Container: `copy-public-files`
**Purpose**: Prepares shared volumes with static files and creates proper symlinks

**Image**: Same as main Bagisto container to ensure consistency

**Tasks**:
- Copies `/var/www/html/public/*` from Docker image to shared `emptyDir` volume
- Creates Laravel storage directory structure in storage PVC
- Recreates the storage symlink: `/public/storage → /var/www/html/storage/app/public`
- Sets proper ownership and permissions

**Volume Mounts**:
- `bagisto-public` (emptyDir) → `/public-shared` (write)
- `bagisto-storage` (PVC) → `/storage-shared` (write)

### 2. Main Container: `bagisto`
**Purpose**: Runs the Laravel/Bagisto PHP application via PHP-FPM

**Image**: Custom multi-architecture Bagisto image with:
- PHP 8.3-FPM
- Redis extension
- All PHP dependencies (Composer packages)
- Compiled frontend assets (Vite build)
- No `.env` file (uses environment variables)

**Tasks**:
- Processes PHP requests via FastCGI (port 9000)
- Handles business logic, database operations, sessions
- Manages file uploads and dynamic content

**Volume Mounts**:
- `bagisto-storage` (PVC) → `/var/www/html/storage` (read-write)
- `bagisto-public` (emptyDir) → `/var/www/html/public` (read-write)

### 3. Sidecar Container: `nginx`
**Purpose**: HTTP web server that handles all client requests

**Image**: `nginx:1.28.1-alpine`

**Tasks**:
- Serves static files directly (CSS, JS, images, themes)
- Proxies PHP requests to main container via FastCGI
- Handles SSL termination (if configured)
- Provides health check endpoints

**Volume Mounts**:
- `bagisto-public` (emptyDir) → `/var/www/html/public` (read-only)
- `bagisto-storage` (PVC) → `/var/www/html/storage` (read-only)
- `nginx-config` (ConfigMap) → `/etc/nginx/conf.d`

## Volume Strategy

### EmptyDir Volume: `bagisto-public`
- **Shared between**: Init container (write), Main container (read-write), Nginx (read-only)
- **Contains**: Static files, build assets, storage symlink
- **Lifecycle**: Recreated on each pod restart
- **Purpose**: Fast access to frequently served static content

### PVC: `bagisto-storage`
- **Shared between**: Init container (write), Main container (read-write), Nginx (read-only)
- **Contains**: Uploads, cache, logs, theme customizations
- **Lifecycle**: Persistent across pod restarts
- **Access Mode**: ReadWriteMany (required for multiple replicas)
- **Purpose**: Persistent data storage

## Critical Symlink Architecture

The storage symlink is crucial for Laravel's file serving:

```
/var/www/html/public/storage → /var/www/html/storage/app/public
```

**Challenge**: EmptyDir volumes don't preserve symlinks from Docker images
**Solution**: Init container recreates symlink after copying files

**Flow**:
1. Docker image contains: `/var/www/html/public/storage` (symlink)
2. `cp -r` copies files but breaks symlink
3. Init container recreates: `ln -sf /var/www/html/storage/app/public /public-shared/storage`
4. Nginx can serve theme images via `/storage/theme/1/image.webp`

## Request Flow

### Static Files (CSS, JS, Images)
```
Client → Gateway → Service → Nginx → Static File (direct serve)
```

### Dynamic PHP Requests
```
Client → Gateway → Service → Nginx → FastCGI → PHP-FPM → Business Logic
```

### Theme Images
```
Client → Gateway → Service → Nginx → Symlink → Storage PVC → Image File
```

## Database & External Services

### Migration Strategy
- **Approach**: Helm pre-install/pre-upgrade Job
- **Tool**: `templates/migration-job.yaml`
- **Safety**: Runs once before any app pods start
- **Concurrency**: Prevents multiple pods from running migrations simultaneously

### MySQL
- **Approach**: Standalone StatefulSet using official MySQL images
- **Reason**: ARM64 compatibility (Bitnami charts lack ARM64 support)
- **Image**: `mysql:9.6.0-oraclelinux9`

### Redis
- **Approach**: Bitnami subchart
- **Usage**: Sessions, cache, queues
- **Configuration**: CommonConfiguration with AOF persistence

## Scaling Considerations

### Horizontal Pod Autoscaling
- ✅ **Storage**: ReadWriteMany PVC supports multiple pod access
- ✅ **Sessions**: Redis-backed (shared across pods)
- ✅ **Database**: MySQL supports multiple connections
- ✅ **Static Files**: Each pod gets its own copy via init container

### Rolling Updates
- Migrations run before new pods start
- Zero-downtime deployments supported
- Storage persists across updates

## Debug Commands & Troubleshooting

### Container Status & Logs
```bash
# Check pod status and events
kubectl describe pod <pod-name>

# View init container logs (file copying)
kubectl logs <pod-name> -c copy-public-files

# View main application logs (PHP-FPM)
kubectl logs <pod-name> -c bagisto

# View web server logs (access/error logs)
kubectl logs <pod-name> -c nginx
```

### File System Inspection
```bash
# Check if public files were copied correctly
kubectl exec deploy/bagisto-bagisto -c bagisto -- ls -la /var/www/html/public/build/

# Verify Nginx can see the same files
kubectl exec deploy/bagisto-bagisto -c nginx -- ls -la /var/www/html/public/build/

# Check if storage symlink exists and works
kubectl exec deploy/bagisto-bagisto -c nginx -- ls -la /var/www/html/public/storage

# Verify theme images exist in storage
kubectl exec deploy/bagisto-bagisto -c bagisto -- find /var/www/html/storage -name "*.webp" | head -5

# Test file accessibility from nginx container
kubectl exec deploy/bagisto-bagisto -c nginx -- test -f /var/www/html/storage/app/public/theme/1/<filename>.webp && echo "File accessible" || echo "File not found"
```

### Database & Connectivity
```bash
# Check if Laravel can connect to database
kubectl exec deploy/bagisto-bagisto -c bagisto -- php artisan db:show

# Verify all migrations are applied
kubectl exec deploy/bagisto-bagisto -c bagisto -- php artisan migrate:status

# Check Redis connectivity
kubectl exec deploy/bagisto-bagisto -c bagisto -- php artisan tinker --execute="echo 'Redis: ' . Redis::ping();"

# Test database tables exist
kubectl exec bagisto-bagisto-mysql-0 -- mysql -u bagisto -pbagisto -D bagisto -e "SHOW TABLES;"
```

### Configuration Verification
```bash
# Verify environment variables are set correctly
kubectl exec deploy/bagisto-bagisto -c bagisto -- printenv | grep -E "(DB_|REDIS_|APP_)"

# Check if cached config is cleared (should not exist in production)
kubectl exec deploy/bagisto-bagisto -c bagisto -- ls -la /var/www/html/bootstrap/cache/config.php

# Test Laravel configuration
kubectl exec deploy/bagisto-bagisto -c bagisto -- php artisan config:show database.connections.mysql.host
```

### Nginx Configuration & Access
```bash
# Check nginx configuration syntax
kubectl exec deploy/bagisto-bagisto -c nginx -- nginx -t

# View access logs for 404 errors
kubectl logs deploy/bagisto-bagisto -c nginx | grep " 404 "

# Check if nginx can resolve PHP-FPM
kubectl exec deploy/bagisto-bagisto -c nginx -- nslookup 127.0.0.1

# Test health check endpoint
kubectl exec deploy/bagisto-bagisto -c nginx -- wget -O- http://localhost/health
```

### Volume Mount Verification
```bash
# Check volume mounts in all containers
kubectl describe pod <pod-name> | grep -A 10 "Volume Mounts:"

# Verify PVC is bound and accessible
kubectl get pvc bagisto-storage

# Check emptyDir usage
kubectl exec deploy/bagisto-bagisto -c bagisto -- df -h | grep /var/www/html/public

# Verify permissions on shared storage
kubectl exec deploy/bagisto-bagisto -c bagisto -- ls -la /var/www/html/storage/
kubectl exec deploy/bagisto-bagisto -c nginx -- ls -la /var/www/html/storage/
```

### Performance & Resource Monitoring
```bash
# Check container resource usage
kubectl top pod <pod-name> --containers

# View container restart counts
kubectl get pods -o wide

# Check if containers are hitting resource limits
kubectl describe pod <pod-name> | grep -A 5 "Last State"

# Monitor nginx worker processes
kubectl exec deploy/bagisto-bagisto -c nginx -- ps aux | grep nginx
```

## Common Issues & Solutions

### 1. Theme Images Return 404
- **Symptom**: `/storage/theme/1/image.webp` returns 404
- **Debug**: Check if storage symlink exists in nginx container
- **Fix**: Ensure init container recreates symlink after copying files

### 2. Install Loop
- **Symptom**: Bagisto installer keeps appearing after completion
- **Debug**: Check if `storage/installed` file exists and has correct permissions
- **Fix**: Ensure container runs as `www-data` user

### 3. Migration Failures
- **Symptom**: Database connection errors or missing tables
- **Debug**: Check migration job logs and database connectivity
- **Fix**: Verify database credentials and network connectivity

### 4. Redis Connection Issues
- **Symptom**: `Class "Redis" not found` or connection timeouts
- **Debug**: Check if Redis extension is installed and service is accessible
- **Fix**: Ensure `pecl install redis` in Dockerfile and correct Redis hostname

### 5. File Permission Errors
- **Symptom**: Permission denied when writing to storage
- **Debug**: Check file ownership and permissions
- **Fix**: Ensure init container sets proper `chown/chmod` and container runs as `www-data`

## Production Recommendations

1. **Security**: Use `existingSecret` for sensitive data (database passwords, app keys)
2. **Resources**: Set appropriate resource requests/limits
3. **Monitoring**: Implement health checks and monitoring
4. **Backups**: Regular backups of MySQL and storage PVC
5. **Updates**: Use rolling updates with readiness probes
6. **SSL**: Configure TLS termination at Gateway level
7. **Caching**: Consider Redis clustering for high availability
8. **Storage**: Use high-performance storage class for database workloads