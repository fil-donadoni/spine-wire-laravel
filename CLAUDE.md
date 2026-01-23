# CLAUDE.md - Spine Wire Laravel

Questo documento contiene le linee guida operative per lavorare sul package **Spine Wire Laravel** (nome composer: `fil-donadoni/spine-wire-laravel`).

## Overview del Progetto

**Spine Wire Laravel** è un package Composer che fornisce file di deployment per applicazioni Laravel su Google Cloud Platform (Cloud Run). Fa parte del progetto Spine insieme a **Spine Core** (`fil-donadoni/spine-core`).

### Cosa Fornisce Questo Package

- **Docker**: Dockerfile con FrankenPHP + Octane, entrypoints per web/queue/jobs
- **CI/CD**: cloudbuild.yaml per Cloud Build
- **Health Check**: Controller e Service copiati nel progetto target + route in web.php
- **Service Providers**: Storage, PubSub

### Cosa NON Fornisce (Gestito dall'App Companion)

Per Terraform e infrastructure-as-code, usa l'app companion:
**[Spine Core](https://github.com/fil-donadoni/spine-core)** (in `../spine-core`)

---

## Stack Tecnologico

- **Backend**: PHP ^8.2, Laravel ^11.0|^12.0
- **Container**: Docker, FrankenPHP + Octane
- **Cloud**: Google Cloud Platform (Cloud Run)
- **Dipendenze**: nessuna dipendenza GCP specifica (logging via stderr)

---

## Struttura del Package

```
spine-wire-laravel/
├── src/
│   ├── Commands/
│   │   └── SetupDevOpsCommand.php    # Comando artisan
│   ├── DevOpsServiceProvider.php     # Service provider principale
│   ├── PubSub/
│   │   └── GoogleCloudPubSubServiceProvider.php
│   └── Storage/
│       └── GoogleCloudStorageServiceProvider.php
├── stubs/
│   ├── .dockerignore
│   ├── app/                          # Copiati nel progetto target
│   │   ├── Http/Controllers/HealthCheckController.php
│   │   └── Services/HealthCheckService.php
│   ├── cicd/
│   │   └── cloudbuild.yaml.stub
│   └── docker/
│       ├── Dockerfile.stub
│       ├── Dockerfile.base.stub
│       ├── README.md
│       ├── entrypoints/
│       │   ├── job-entrypoint.sh
│       │   ├── queue-entrypoint.sh
│       │   └── service-entrypoint.sh
│       └── php/
│           └── php.ini
├── config/
│   └── devops.php
└── composer.json
```

**Nota**: I file in `stubs/app/` vengono copiati nella cartella `app/` del progetto target durante `devops:setup`. Questo permette di non installare il package in produzione.

---

## Comando `devops:setup`

### Signature

```
php artisan devops:setup
    {--project-id= : GCP Project ID}
    {--region=europe-west1 : GCP Region}
    {--client-name= : Client name (defaults to project directory name)}
    {--app-name=backend : Application name}
    {--force : Overwrite existing files}
    {--ignore-extras : Skip prompts for Docker extras}
```

### Output Generato

```
project-root/
├── docker/
│   ├── Dockerfile              # Processato da .stub
│   ├── Dockerfile.base
│   ├── README.md
│   ├── entrypoints/
│   │   ├── job-entrypoint.sh
│   │   ├── queue-entrypoint.sh
│   │   └── service-entrypoint.sh
│   └── php/
│       └── php.ini
├── cloudbuild.yaml             # Processato da .stub
├── .dockerignore
├── app/
│   ├── Http/Controllers/
│   │   └── HealthCheckController.php  # Copiato
│   └── Services/
│       └── HealthCheckService.php     # Copiato
└── routes/web.php              # Modificato: aggiunta route /health
```

**Importante**: I file in `app/` vengono copiati (non symlinkati) così il progetto funziona anche senza il package installato in produzione.

### Placeholder Supportati

```
{{PROJECT_ID}}      → GCP Project ID
{{CLIENT_NAME}}     → Client name (sanitized)
{{GCP_REGION}}      → GCP Region
{{APP_NAME}}        → Application name
{{NODE_VERSION}}    → Node.js version (se frontend enabled)
{{PACKAGE_MANAGER}} → npm o pnpm (se frontend enabled)
```

### Conditional Blocks (Dockerfile)

```dockerfile
{{#IF:ENABLE_FRONTEND}}
# Questo blocco è incluso solo se enable_frontend = true
RUN npm install
{{/IF:ENABLE_FRONTEND}}

{{#IF:ENABLE_IMAGICK}}
RUN apk add imagemagick
{{/IF:ENABLE_IMAGICK}}

{{#IF:ENABLE_REDIS}}
RUN docker-php-ext-install redis
{{/IF:ENABLE_REDIS}}
```

---

## Frontend Build Configuration

Il Dockerfile supporta sia **Vite** che **Laravel Mix** out-of-the-box.

### Vite (Default)

Nessuna configurazione necessaria. Il Dockerfile copia automaticamente `public/build`.

### Laravel Mix

Il Dockerfile supporta Laravel Mix copiando:
- `webpack.mix.js` durante il build
- Il file `artisan` (necessario per la detection automatica del progetto Laravel)
- L'intera directory `public/` (include `js/`, `css/`, `mix-manifest.json`)

**Nota tecnica:** Laravel Mix cerca il file `artisan` per riconoscere che è un progetto Laravel e impostare automaticamente il `publicPath`. Il Dockerfile copia questo file nel node-builder stage per garantire la corretta compilazione con `.version()` e `chunkFilename` personalizzati.

---

## Development Workflow

### Setup Locale (Path Repository)

```bash
# Nel progetto target
cd ~/code/my-laravel-app

composer config repositories.local '{
  "type": "path",
  "url": "../spine-wire-laravel",
  "options": {"symlink": true}
}'

composer require fil-donadoni/spine-wire-laravel:@dev
```

### Iterazione Rapida

```bash
# Modifica nel package
vim ~/code/spine/spine-wire-laravel/src/Commands/SetupDevOpsCommand.php

# Testa nel progetto target
cd ~/code/my-laravel-app
php artisan devops:setup --force
```

---

## CHECKLIST Pre-Commit

- [ ] `php -l src/Commands/SetupDevOpsCommand.php`
- [ ] `php -l config/devops.php`
- [ ] `bash -n stubs/docker/entrypoints/*.sh`
- [ ] Placeholder corretti nei file .stub

---

## Convenzioni

### Naming

- **Client name**: lowercase, hyphen-separated (es: `my-company`)
- **App name**: lowercase, hyphen-separated (es: `backend`, `api`)

### File .stub

- Usano estensione `.stub`
- Vengono processati e rinominati (`.stub` rimosso)
- Placeholder: `{{NOME_PLACEHOLDER}}`
- Conditional: `{{#IF:FEATURE}}...{{/IF:FEATURE}}`

---

## Relazione con Spine Core

Questo package è **companion** dell'app [Spine Core](https://github.com/fil-donadoni/spine-core) (in `../spine-core`):

| Componente | Responsabilità |
|------------|----------------|
| **Spine Wire Laravel** (questo package) | Docker, cloudbuild, healthcheck |
| **Spine Core** | Terraform, infrastructure, configurazione UI |

### Flusso Consigliato

1. Configura progetto in **Spine Core**
2. Genera file Terraform
3. Installa **Spine Wire Laravel** nel progetto Laravel
4. `php artisan devops:setup` → genera Docker/cloudbuild
5. Deploy con Terraform scripts

---

## Git Workflow

### Commit Messages

Format: `<type>: <subject>`

Types:
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation
- `refactor`: Code refactoring

Esempi:
```
feat: add Redis extension support to Dockerfile
fix: correct placeholder replacement in cloudbuild.yaml
docs: update README with new setup instructions
```

---

## Riferimenti Rapidi

### Comandi Utili

```bash
# Validazione
php -l src/Commands/SetupDevOpsCommand.php
bash -n stubs/docker/entrypoints/*.sh

# Testing
php artisan devops:setup --force --ignore-extras

# Docker build locale
docker build -f docker/Dockerfile -t test .
```

### File Chiave

- `src/Commands/SetupDevOpsCommand.php`: Logica principale
- `stubs/docker/Dockerfile.stub`: Template Dockerfile
- `stubs/cicd/cloudbuild.yaml.stub`: Template Cloud Build
- `config/devops.php`: Configurazione package

---

**Versione**: 3.0.0
**Ultimo aggiornamento**: 2025-12-23
