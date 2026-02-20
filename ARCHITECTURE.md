# Bagisto Helm Chart Architecture

This document explains the containerized architecture of the Bagisto e-commerce platform deployment using Kubernetes and Helm, specifically designed for multi-architecture support (ARM64/AMD64) and production scalability.

## Overview

The deployment uses [FrankenPHP](https://frankenphp.dev) — a modern PHP application server built on [Caddy](https://caddyserver.com) — to serve the entire application from a single container. FrankenPHP embeds the PHP runtime directly into Caddy, eliminating the need for a separate web server sidecar (e.g., Nginx) and the FastCGI protocol between them.

## Container Architecture

### Pod Structure
```
┌─────────────────────────────────────────────────────────────┐
│                           Pod                               │
│                                                             │
│  ┌─────────────────┐                                        │
│  │  Init Container │  (runs first, completes, then exits)   │
│  │  init-storage   │                                        │
│  └─────────────────┘                                        │
│                                                             │
│  ┌─────────────────────────────────────────────┐            │
│  │           Main Container                    │            │
│  │             bagisto                         │            │
│  │          (FrankenPHP)                       │            │
│  │                                             │            │
│  │   Caddy (HTTP)  ←──→  Embedded PHP Runtime  │            │
│  │    Port: 8080                               │            │
│  └─────────────────────────────────────────────┘            │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Why FrankenPHP instead of PHP-FPM + Nginx?

The traditional PHP-on-Kubernetes pattern uses PHP-FPM as a backend with Nginx as a sidecar proxy, connected via the FastCGI protocol over a shared emptyDir volume. This requires:

- An init container to copy public files from the PHP image into a shared volume
- An emptyDir volume for Nginx to serve static files
- A ConfigMap for Nginx configuration
- Symlink recreation on every pod restart

FrankenPHP simplifies this by embedding PHP directly into the Caddy web server:

| Aspect | PHP-FPM + Nginx (before) | FrankenPHP (now) |
|--------|--------------------------|-------------------|
| Containers per pod | 3 (init + PHP-FPM + Nginx) | 2 (init + FrankenPHP) |
| Volumes | 3 (emptyDir + PVC + ConfigMap) | 2 (PVC + ConfigMap) |
| Static file serving | Nginx reads from shared emptyDir | Caddy serves from image filesystem |
| PHP execution | FastCGI over TCP (port 9000) | In-process (no network overhead) |
| Symlink handling | Recreated by init container | Baked into the Docker image at build time |

## Container Responsibilities

### 1. Init Container: `init-storage`
**Purpose**: Creates the Laravel storage directory structure on the persistent volume

**Image**: Same as main Bagisto container to ensure consistency

**Tasks**:
- Creates Laravel storage subdirectories on the PVC: `app/public`, `framework/cache/data`, `framework/sessions`, `framework/testing`, `framework/views`, `logs`

**Volume Mounts**:
- `bagisto-storage` (PVC) → `/storage-shared` (write)

### 2. Main Container: `bagisto`
**Purpose**: Serves the entire application — both static files and PHP — via FrankenPHP

**Image**: Custom multi-architecture Bagisto image with:
- FrankenPHP 1.x (Caddy + embedded PHP 8.3)
- Redis, GD, Imagick, Intl, and other PHP extensions
- All PHP dependencies (Composer packages)
- Compiled frontend assets (Vite build)
- Storage symlink (`public/storage → storage/app/public`) baked in at build time
- No `.env` file (uses environment variables from Kubernetes)

**Tasks**:
- Serves static files directly (CSS, JS, images, fonts) with compression and caching
- Executes PHP for dynamic requests (no FastCGI hop)
- Handles business logic, database operations, sessions
- Manages file uploads and dynamic content
- Provides the `/health` endpoint for Kubernetes probes

**Volume Mounts**:
- `bagisto-storage` (PVC) → `/var/www/html/storage` (read-write)
- `caddyfile` (ConfigMap) → `/etc/caddy` (read-only)

**Port**: 8080 (non-privileged, container runs as `www-data`)

## Volume Strategy

### PVC: `bagisto-storage`
- **Used by**: Init container (write), Main container (read-write)
- **Contains**: Uploads, cache, logs, sessions, theme customizations
- **Lifecycle**: Persistent across pod restarts and upgrades
- **Access Mode**: ReadWriteMany (required for multiple replicas)

### ConfigMap: `caddyfile`
- **Used by**: Main container (read-only)
- **Contains**: Caddy/FrankenPHP server configuration (Caddyfile)
- **Purpose**: Configures HTTP serving, security headers, health endpoint, compression, upload limits

## Storage Symlink

Laravel requires a symlink to serve uploaded files via the web:

```
/var/www/html/public/storage → /var/www/html/storage/app/public
```

With the FrankenPHP approach, this symlink is **created at Docker build time** (`ln -sf` in the Dockerfile). At runtime, when the storage PVC is mounted at `/var/www/html/storage`, the symlink resolves through the mount to the PVC's `app/public` directory. No init container logic is needed for this.

## Request Flow

### Static Files (CSS, JS, Images)
```
Client → Ingress → Service → FrankenPHP/Caddy → Static File (direct serve + gzip/zstd)
```

### Dynamic PHP Requests
```
Client → Ingress → Service → FrankenPHP/Caddy → Embedded PHP → Business Logic
```

### Uploaded Files (Theme Images, etc.)
```
Client → Ingress → Service → FrankenPHP/Caddy → public/storage (symlink) → Storage PVC
```

All three flows are handled by the same process — there is no network hop between a web server and a PHP backend.

## Caddyfile Configuration

The Caddyfile is provided as a Kubernetes ConfigMap and mounted at `/etc/caddy/Caddyfile`. It configures:

- **FrankenPHP mode**: Enables the embedded PHP runtime
- **Port 8080**: Non-privileged port for `www-data` user
- **Security headers**: `X-Frame-Options`, `X-Content-Type-Options`, strips `Server` and `X-Powered-By`
- **Health endpoint**: `/health` returns 200 for liveness/readiness probes
- **Upload limit**: 100MB max request body
- **Compression**: zstd and gzip encoding
- **Hidden file protection**: Denies access to dotfiles (except `.well-known`)
- **`php_server` directive**: Handles `try_files`, static serving, and PHP execution in one directive

## Database & External Services

### Migration Strategy
- **Approach**: Helm post-install/post-upgrade Job
- **Tool**: `templates/migration-job.yaml`
- **Safety**: Waits for database readiness before running `php artisan migrate --force`

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
- **Storage**: ReadWriteMany PVC supports multiple pod access
- **Sessions**: Redis-backed (shared across pods)
- **Database**: MySQL supports multiple connections
- **Static Files**: Served from the image filesystem (no shared volume needed)

### Rolling Updates
- Migrations run after new resources are deployed (post-install/post-upgrade hook)
- Zero-downtime deployments supported via readiness probes
- Storage persists across updates

## Debug Commands & Troubleshooting

### Container Status & Logs
```bash
# Check pod status and events
kubectl describe pod <pod-name>

# View init container logs (storage setup)
kubectl logs <pod-name> -c init-storage

# View application logs (FrankenPHP serves both HTTP and PHP)
kubectl logs <pod-name> -c bagisto
```

### File System Inspection
```bash
# Check if public files and build assets exist
kubectl exec deploy/bagisto-bagisto -c bagisto -- ls -la /var/www/html/public/build/

# Check if storage symlink exists and resolves correctly
kubectl exec deploy/bagisto-bagisto -c bagisto -- ls -la /var/www/html/public/storage

# Verify theme images exist in storage PVC
kubectl exec deploy/bagisto-bagisto -c bagisto -- find /var/www/html/storage -name "*.webp" | head -5

# Verify storage directory structure
kubectl exec deploy/bagisto-bagisto -c bagisto -- ls -la /var/www/html/storage/
```

### Database & Connectivity
```bash
# Check if Laravel can connect to database
kubectl exec deploy/bagisto-bagisto -c bagisto -- php artisan db:show

# Verify all migrations are applied
kubectl exec deploy/bagisto-bagisto -c bagisto -- php artisan migrate:status

# Check Redis connectivity
kubectl exec deploy/bagisto-bagisto -c bagisto -- php artisan tinker --execute="echo 'Redis: ' . Redis::ping();"
```

### Configuration Verification
```bash
# Verify environment variables are set correctly
kubectl exec deploy/bagisto-bagisto -c bagisto -- printenv | grep -E "(DB_|REDIS_|APP_)"

# Test Laravel configuration
kubectl exec deploy/bagisto-bagisto -c bagisto -- php artisan config:show database.connections.mysql.host

# Check the active Caddyfile
kubectl exec deploy/bagisto-bagisto -c bagisto -- cat /etc/caddy/Caddyfile
```

### HTTP & Health Checks
```bash
# Test health check endpoint from inside the pod
kubectl exec deploy/bagisto-bagisto -c bagisto -- wget -qO- http://localhost:8080/health

# Check Caddy is listening
kubectl exec deploy/bagisto-bagisto -c bagisto -- wget -qO- --spider http://localhost:8080/
```

### Volume Mount Verification
```bash
# Check volume mounts
kubectl describe pod <pod-name> | grep -A 10 "Volume Mounts:"

# Verify PVC is bound and accessible
kubectl get pvc bagisto-bagisto-storage

# Verify permissions on storage
kubectl exec deploy/bagisto-bagisto -c bagisto -- ls -la /var/www/html/storage/
```

### Performance & Resource Monitoring
```bash
# Check container resource usage
kubectl top pod <pod-name> --containers

# View container restart counts
kubectl get pods -o wide

# Check if containers are hitting resource limits
kubectl describe pod <pod-name> | grep -A 5 "Last State"
```

## Common Issues & Solutions

### 1. Theme Images Return 404
- **Symptom**: `/storage/theme/1/image.webp` returns 404
- **Debug**: Check if storage symlink exists: `ls -la /var/www/html/public/storage`
- **Fix**: Ensure the Dockerfile creates the symlink with `ln -sf` and the storage PVC is mounted

### 2. Install Loop
- **Symptom**: Bagisto installer keeps appearing after completion
- **Debug**: Check if `storage/installed` file exists and has correct permissions
- **Fix**: Ensure container runs as `www-data` user and `fsGroup: 33` is set

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
- **Debug**: Check file ownership and permissions on the PVC
- **Fix**: Ensure pod `securityContext.fsGroup: 33` is set and container runs as `www-data`

### 6. Caddy/FrankenPHP Fails to Start
- **Symptom**: Container crashes on startup, logs show Caddyfile errors
- **Debug**: `kubectl logs <pod-name> -c bagisto` for Caddy error output
- **Fix**: Verify the Caddyfile ConfigMap syntax and that `/data/caddy` and `/config/caddy` are writable

## Production Recommendations

1. **Security**: Use `existingSecret` for sensitive data (database passwords, app keys)
2. **Resources**: Set appropriate resource requests/limits via `resources` in values.yaml
3. **Monitoring**: Leverage the `/health` endpoint for uptime monitoring
4. **Backups**: Regular backups of MySQL and storage PVC
5. **Updates**: Use rolling updates with readiness probes
6. **SSL**: Configure TLS termination at Ingress/Gateway level (Caddy's auto-HTTPS is disabled)
7. **Caching**: Consider Redis clustering for high availability
8. **Storage**: Use high-performance storage class for database workloads
