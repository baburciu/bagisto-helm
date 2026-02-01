# Bagisto Helm Chart

A Helm chart for deploying Bagisto e-commerce platform on Kubernetes with high availability support.

## Features

- **High Availability**: Supports horizontal scaling with multiple replicas
- **Kubernetes Native**: Designed for K8s with Gateway API support (HTTPRoute created separately)
- **Managed Services Support**: Can use external MySQL and Redis (commented examples in values.yaml)
- **Subcharts**: Includes MySQL, Redis, and Elasticsearch as dependencies
- **Persistent Storage**: Configurable storage for application data, database, and search index
- **Complete Laravel/Bagisto Configuration**: All environment variables pre-configured

## Prerequisites

- Kubernetes
- Helm
- Storage class that supports ReadWriteMany (for multi-replica deployments)

## Installation

### 1. Add Bitnami repository for subcharts

```bash
helm repo add bitnami https://charts.bitnami.com/bitnami
helm repo update
```

### 2. Update chart dependencies

```bash
cd bagisto-helm
helm dependency update
```

### 3. Install the chart

```bash
helm install bagisto . -n bagisto --create-namespace
```

### 4. Customize values (optional)

```bash
helm install bagisto . -n bagisto --create-namespace -f custom-values.yaml
```

## Configuration

### Key Configuration Options

| Parameter | Description | Default |
|-----------|-------------|---------|
| `replicaCount` | Number of Bagisto replicas | `2` |
| `image.repository` | Bagisto image repository | `bagisto/bagisto` |
| `image.tag` | Bagisto image tag | `latest` |
| `mysql.enabled` | Enable MySQL subchart | `true` |
| `redis.enabled` | Enable Redis subchart | `true` |
| `elasticsearch.enabled` | Enable Elasticsearch | `true` |
| `app.name` | Application name | `Bagisto` |
| `app.env` | Application environment | `production` |
| `app.url` | Application URL | `http://localhost` |
| `app.existingSecret.name` | Existing secret for APP_KEY | `""` |
| `database.existingSecret.name` | Existing secret for DB password | `""` |
| `mysql.auth.existingSecret` | Existing secret for MySQL passwords | `""` |
| `session.driver` | Session driver (file/redis) | `redis` |
| `cache.store` | Cache store (file/redis) | `redis` |
| `queue.connection` | Queue connection | `redis` |
| `storage.size` | Size of persistent storage | `10Gi` |

### Using Existing Secrets (Recommended for Production)

For security, avoid storing sensitive credentials in `values.yaml`. Instead, create Kubernetes secrets and reference them:

#### Create Secret for Application Key

```bash
kubectl create secret generic bagisto-app-secret \
  --from-literal=app-key='base64:YOUR_APP_KEY_HERE' \
  -n bagisto
```

Then reference it in `values.yaml`:

```yaml
app:
  existingSecret:
    name: "bagisto-app-secret"
    keyKey: "app-key"
```

#### Create Secret for Database Password

```bash
kubectl create secret generic bagisto-db-secret \
  --from-literal=db-password='YOUR_DB_PASSWORD_HERE' \
  -n bagisto
```

Then reference it in `values.yaml`:

```yaml
database:
  existingSecret:
    name: "bagisto-db-secret"
    passwordKey: "db-password"
```

#### Create Secret for MySQL Passwords

```bash
kubectl create secret generic bagisto-mysql-secret \
  --from-literal=mysql-password='YOUR_MYSQL_PASSWORD' \
  --from-literal=mysql-root-password='YOUR_ROOT_PASSWORD' \
  -n bagisto
```

Then reference it in `values.yaml`:

```yaml
mysql:
  auth:
    existingSecret: "bagisto-mysql-secret"
```

**Note:** When using `existingSecret`, the plaintext password values in `values.yaml` are ignored.

### Using External Services

To use external MySQL or Redis (managed services), uncomment and configure the external sections in `values.yaml`:

```yaml
database:
  external:
    enabled: true
    host: "prod-mysql.example.com"
    port: 3306
    username: "bagisto"
    password: "prodpassword"
    name: "bagisto"

redis:
  external:
    enabled: true
    host: "prod-redis.example.com"
    port: 6379
```

Then disable the subcharts:

```yaml
mysql:
  enabled: false

redis:
  enabled: false
```

## License

Same as [Bagisto](https://github.com/bagisto/bagisto) & [Stripe Payment Gateway](https://github.com/codenteq/stripe-payment-gateway)
