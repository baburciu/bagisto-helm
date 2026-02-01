# bagisto

A Helm chart for deploying Bagisto on Kubernetes

![Version: 0.1.0](https://img.shields.io/badge/Version-0.1.0-informational?style=flat-square) ![AppVersion: 1.0.0](https://img.shields.io/badge/AppVersion-1.0.0-informational?style=flat-square)

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

## Requirements

| Repository | Name | Version |
|------------|------|---------|
| https://charts.bitnami.com/bitnami | mysql | 14.0.3 |
| https://charts.bitnami.com/bitnami | redis | 24.1.2 |

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

## Configuration

## Values

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| app | object | `{"currency":"EUR","debug":false,"env":"production","existingSecret":{"keyKey":"app-key","name":""},"key":"base64:CHANGE_THIS_KEY_RUN_php_artisan_key_generate","locale":"en","name":"Bagisto","timezone":"UTC","url":"http://localhost"}` | Bagisto application configuration |
| app.currency | string | `"EUR"` | Application currency code |
| app.debug | bool | `false` | Enable debug mode |
| app.env | string | `"production"` | Application environment (local, production) |
| app.existingSecret | object | `{"keyKey":"app-key","name":""}` | Use existing Kubernetes secret for APP_KEY (recommended for production) |
| app.existingSecret.keyKey | string | `"app-key"` | Key in the secret containing the APP_KEY value |
| app.existingSecret.name | string | `""` | Name of the secret containing APP_KEY |
| app.key | string | `"base64:CHANGE_THIS_KEY_RUN_php_artisan_key_generate"` | Laravel APP_KEY (base64-encoded 32-byte random string). Generate with: echo "base64:$(openssl rand -base64 32)" |
| app.locale | string | `"en"` | Application locale |
| app.name | string | `"Bagisto"` | Application name |
| app.timezone | string | `"UTC"` | Application timezone |
| app.url | string | `"http://localhost"` | Application URL |
| cache | object | `{"prefix":"bagisto_cache","store":"redis"}` | Cache Configuration |
| cache.prefix | string | `"bagisto_cache"` | Cache key prefix |
| cache.store | string | `"redis"` | Cache store (file, redis, database) |
| databaseConfig | object | `{"existingSecret":{"name":"","passwordKey":"db-password"},"external":{"enabled":false,"host":"prod-mysql.example.com","name":"bagisto","password":"prodpassword","port":3306,"username":"bagisto"},"host":"{{ .Release.Name }}-mysql","name":"bagisto","password":"bagisto","port":3306,"username":"bagisto"}` | MySQL Database Configuration |
| databaseConfig.existingSecret | object | `{"name":"","passwordKey":"db-password"}` | Use existing Kubernetes secret for database password (recommended for production) |
| databaseConfig.existingSecret.name | string | `""` | Name of the secret containing database password |
| databaseConfig.existingSecret.passwordKey | string | `"db-password"` | Key in the secret containing the password value |
| databaseConfig.external | object | `{"enabled":false,"host":"prod-mysql.example.com","name":"bagisto","password":"prodpassword","port":3306,"username":"bagisto"}` | External MySQL configuration (for managed services like AWS RDS) |
| databaseConfig.external.enabled | bool | `false` | Enable external MySQL |
| databaseConfig.external.host | string | `"prod-mysql.example.com"` | External MySQL host |
| databaseConfig.external.name | string | `"bagisto"` | External MySQL database name |
| databaseConfig.external.password | string | `"prodpassword"` | External MySQL password (use existingSecret instead for production) |
| databaseConfig.external.port | int | `3306` | External MySQL port |
| databaseConfig.external.username | string | `"bagisto"` | External MySQL username |
| databaseConfig.host | string | `"{{ .Release.Name }}-mysql"` | Database host (supports template syntax) |
| databaseConfig.name | string | `"bagisto"` | Database name |
| databaseConfig.password | string | `"bagisto"` | Database password (use existingSecret instead for production) |
| databaseConfig.port | int | `3306` | Database port |
| databaseConfig.username | string | `"bagisto"` | Database username |
| elasticsearch | object | `{"enabled":false,"esConfig":{"elasticsearch.yml":"xpack.security.enabled: false\ndiscovery.type: single-node\n"},"esJavaOpts":"-Xms256m -Xmx256m","image":"docker.elastic.co/elasticsearch/elasticsearch","imageTag":"9.2.4","replicas":1,"resources":{"limits":{"cpu":"1000m","memory":"1Gi"},"requests":{"cpu":"100m","memory":"512Mi"}},"volumeClaimTemplate":{"accessModes":["ReadWriteOnce"],"resources":{"requests":{"storage":"10Gi"}}}}` | Elasticsearch Configuration (for advanced search functionality) |
| elasticsearch.enabled | bool | `false` | Enable Elasticsearch deployment |
| elasticsearch.esConfig | object | `{"elasticsearch.yml":"xpack.security.enabled: false\ndiscovery.type: single-node\n"}` | Elasticsearch configuration file |
| elasticsearch.esJavaOpts | string | `"-Xms256m -Xmx256m"` | Elasticsearch Java options (heap size) |
| elasticsearch.image | string | `"docker.elastic.co/elasticsearch/elasticsearch"` | Elasticsearch container image |
| elasticsearch.imageTag | string | `"9.2.4"` | Elasticsearch image tag |
| elasticsearch.replicas | int | `1` | Number of Elasticsearch replicas |
| elasticsearch.resources | object | `{"limits":{"cpu":"1000m","memory":"1Gi"},"requests":{"cpu":"100m","memory":"512Mi"}}` | Elasticsearch resource requests and limits |
| elasticsearch.volumeClaimTemplate | object | `{"accessModes":["ReadWriteOnce"],"resources":{"requests":{"storage":"10Gi"}}}` | Elasticsearch persistent volume configuration |
| image | object | `{"pullPolicy":"IfNotPresent","repository":"docker.io/webkul/bagisto","tag":"2.3.11"}` | Container image settings |
| image.pullPolicy | string | `"IfNotPresent"` | Image pull policy |
| image.repository | string | `"docker.io/webkul/bagisto"` | Bagisto container image repository |
| image.tag | string | `"2.3.11"` | Bagisto container image tag |
| mail | object | `{"admin":{"address":"admin@example.com","name":"Bagisto Admin"},"enabled":false,"from":{"address":"shop@example.com","name":"Bagisto Shop"},"host":"mailpit","mailer":"smtp","port":1025}` | Mail Configuration |
| mail.admin | object | `{"address":"admin@example.com","name":"Bagisto Admin"}` | Admin email configuration |
| mail.admin.address | string | `"admin@example.com"` | Admin email address |
| mail.admin.name | string | `"Bagisto Admin"` | Admin email name |
| mail.enabled | bool | `false` | Enable mail sending |
| mail.from | object | `{"address":"shop@example.com","name":"Bagisto Shop"}` | From email address configuration |
| mail.from.address | string | `"shop@example.com"` | From email address |
| mail.from.name | string | `"Bagisto Shop"` | From email name |
| mail.host | string | `"mailpit"` | SMTP host |
| mail.mailer | string | `"smtp"` | Mail driver (smtp, sendmail, mailgun, ses) |
| mail.port | int | `1025` | SMTP port |
| mysql | object | `{"architecture":"standalone","auth":{"database":"bagisto","existingSecret":"","password":"bagisto","rootPassword":"root","username":"bagisto"},"enabled":true,"primary":{"persistence":{"enabled":true,"size":"10Gi","storageClass":""}}}` | MySQL Subchart Configuration (Bitnami MySQL). Set enabled to false when using external database. |
| mysql.architecture | string | `"standalone"` | MySQL architecture (standalone or replication) |
| mysql.auth | object | `{"database":"bagisto","existingSecret":"","password":"bagisto","rootPassword":"root","username":"bagisto"}` | MySQL authentication configuration |
| mysql.auth.database | string | `"bagisto"` | MySQL database name |
| mysql.auth.existingSecret | string | `""` | Use existing secret for MySQL passwords (recommended for production). If set, auth.password and auth.rootPassword are ignored |
| mysql.auth.password | string | `"bagisto"` | MySQL user password |
| mysql.auth.rootPassword | string | `"root"` | MySQL root password |
| mysql.auth.username | string | `"bagisto"` | MySQL user name |
| mysql.enabled | bool | `true` | Enable MySQL subchart deployment |
| mysql.primary | object | `{"persistence":{"enabled":true,"size":"10Gi","storageClass":""}}` | MySQL primary configuration |
| mysql.primary.persistence | object | `{"enabled":true,"size":"10Gi","storageClass":""}` | MySQL persistence configuration |
| mysql.primary.persistence.enabled | bool | `true` | Enable persistence for MySQL |
| mysql.primary.persistence.size | string | `"10Gi"` | MySQL persistent volume size |
| mysql.primary.persistence.storageClass | string | `""` | MySQL storage class name |
| queue | object | `{"connection":"redis"}` | Queue Configuration |
| queue.connection | string | `"redis"` | Queue connection driver (sync, redis, database) |
| redis | object | `{"architecture":"standalone","auth":{"enabled":false},"enabled":true,"master":{"command":["redis-server","--save","20","1","--loglevel","warning"],"persistence":{"enabled":true,"size":"2Gi","storageClass":""}}}` | Redis Subchart Configuration (Bitnami Redis). Set enabled to false when using external Redis. |
| redis.architecture | string | `"standalone"` | Redis architecture (standalone or replication) |
| redis.auth | object | `{"enabled":false}` | Redis authentication configuration |
| redis.auth.enabled | bool | `false` | Enable Redis password authentication |
| redis.enabled | bool | `true` | Enable Redis subchart deployment |
| redis.master | object | `{"command":["redis-server","--save","20","1","--loglevel","warning"],"persistence":{"enabled":true,"size":"2Gi","storageClass":""}}` | Redis master configuration |
| redis.master.command | list | `["redis-server","--save","20","1","--loglevel","warning"]` | Redis server command with custom arguments for persistence and logging |
| redis.master.persistence | object | `{"enabled":true,"size":"2Gi","storageClass":""}` | Redis persistence configuration |
| redis.master.persistence.enabled | bool | `true` | Enable persistence for Redis |
| redis.master.persistence.size | string | `"2Gi"` | Redis persistent volume size |
| redis.master.persistence.storageClass | string | `""` | Redis storage class name |
| redisConfig | object | `{"existingSecret":{"name":"","passwordKey":"redis-password"},"external":{"enabled":false,"host":"prod-redis.example.com","port":6379},"host":"{{ .Release.Name }}-redis-master","port":6379}` | Redis Connection Configuration |
| redisConfig.existingSecret | object | `{"name":"","passwordKey":"redis-password"}` | Use existing Kubernetes secret for Redis password (recommended for production) |
| redisConfig.existingSecret.name | string | `""` | Name of the secret containing Redis password |
| redisConfig.existingSecret.passwordKey | string | `"redis-password"` | Key in the secret containing the password value |
| redisConfig.external | object | `{"enabled":false,"host":"prod-redis.example.com","port":6379}` | External Redis configuration (for managed services like AWS ElastiCache) |
| redisConfig.external.enabled | bool | `false` | Enable external Redis |
| redisConfig.external.host | string | `"prod-redis.example.com"` | External Redis host |
| redisConfig.external.port | int | `6379` | External Redis port |
| redisConfig.host | string | `"{{ .Release.Name }}-redis-master"` | Redis host (supports template syntax) |
| redisConfig.port | int | `6379` | Redis port |
| replicaCount | int | `2` | Number of Bagisto pod replicas |
| service | object | `{"port":80,"type":"ClusterIP"}` | Kubernetes service configuration |
| service.port | int | `80` | Service port |
| service.type | string | `"ClusterIP"` | Service type |
| session | object | `{"driver":"redis","lifetime":120}` | Session Configuration |
| session.driver | string | `"redis"` | Session driver (file, redis, database) |
| session.lifetime | int | `120` | Session lifetime in minutes |
| storage | object | `{"accessMode":"ReadWriteMany","enabled":true,"size":"10Gi","storageClass":""}` | Application Storage (for uploads, cache, etc.) |
| storage.accessMode | string | `"ReadWriteMany"` | Storage access mode (ReadWriteMany required for multiple replicas) |
| storage.enabled | bool | `true` | Enable persistent storage for Bagisto uploads and cache |
| storage.size | string | `"10Gi"` | Storage size |
| storage.storageClass | string | `""` | Storage class name (leave empty for default) |

### Using Existing Secrets (Recommended for Production)

For security, avoid storing sensitive credentials in `values.yaml`. Instead, create Kubernetes secrets and reference them.

#### Generate and Create Secret for Application Key

First, generate a Laravel APP_KEY:

```bash
# Generate a base64-encoded 32-byte random string
echo "base64:$(openssl rand -base64 32)"
```

Then create the secret:

```bash
kubectl create secret generic bagisto-app-secret \
  --from-literal=app-key='base64:YOUR_GENERATED_KEY_HERE' \
  -n bagisto
```

Reference it in `values.yaml`:

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

Reference it in `values.yaml`:

```yaml
databaseConfig:
  existingSecret:
    name: "bagisto-db-secret"
    passwordKey: "db-password"
```

#### Create Secret for Redis Password

```bash
kubectl create secret generic bagisto-redis-secret \
  --from-literal=redis-password='YOUR_REDIS_PASSWORD_HERE' \
  -n bagisto
```

Reference it in `values.yaml`:

```yaml
redisConfig:
  existingSecret:
    name: "bagisto-db-secret"
    passwordKey: "db-password"
```

#### External MySQL/Redis Configuration

**Important:** When using external MySQL and/or Redis, you must manually disable the MySQL and/or Redis subchart(s):

```yaml
mysql:
  enabled: false

redis:
  enabled: false
```

> NOTE!
> The subcharts are not automatically disabled when external services are configured. You must explicitly set `mysql.enabled: false` or `redis.enabled: false` to avoid deploying unnecessary resources.

## License

Same as [Bagisto](https://github.com/bagisto/bagisto) & [Stripe Payment Gateway](https://github.com/codenteq/stripe-payment-gateway)

----------------------------------------------
Autogenerated from chart metadata using [helm-docs v1.14.2](https://github.com/norwoodj/helm-docs/releases/v1.14.2)
