# shipkit

A small Laravel 12 app used to show how an application is packaged, tested, released and deployed: Docker images, separate containers for the web server, the PHP process, the queue worker and the scheduler, a CI pipeline, image publishing and a deploy script that rolls back on its own when the new version is unhealthy.

The app itself is tiny on purpose. It has one page, `/status`, which reports whether the database and cache work, which version is running, and when the queue worker last ran a job. That one page is enough to prove every container is wired up.

```json
{"version":"v1.0.0","database":"ok","cache":"ok","worker_heartbeat_seconds_ago":12}
```

## What is in the box

| Piece | Where |
|---|---|
| Multi-stage `Dockerfile` with two targets: `app` (PHP-FPM) and `web` (nginx with the public files) | `Dockerfile`, `docker/` |
| Local stack: web, app, worker, scheduler, MySQL 8.4, Redis, plus a one-off migrate step | `docker-compose.yml` |
| CI: Pint, tests, and a check that both images still build | `.github/workflows/ci.yml` |
| Release: push a `v*` tag, both images are built and pushed to GitHub Container Registry | `.github/workflows/release.yml` |
| Server deploy: compose file and `deploy.sh` | `deploy/` |

## Run the stack on your machine

```bash
export APP_KEY=$(php artisan key:generate --show)   # needs composer install first, or use any base64:... key
docker compose up -d --build
curl localhost:8080/status
```

After about a minute the scheduler has queued a heartbeat job and the worker has run it, and `worker_heartbeat_seconds_ago` stops being `null`.

```bash
docker compose ps
docker compose logs -f worker
docker compose down -v      # stop and delete the data
```

## Releasing

```bash
git tag v1.0.1
git push origin v1.0.1
```

The Release workflow builds `ghcr.io/<owner>/shipkit-app:v1.0.1` and `shipkit-web:v1.0.1`. The version is baked into the image and shows up in `/status`.

## Deploying to a server

On the server (any machine with Docker and the compose plugin):

```bash
mkdir -p /opt/shipkit && cd /opt/shipkit
# copy deploy/docker-compose.prod.yml and deploy/deploy.sh here, then:
cp .env.prod.example .env.prod      # fill in the values
./deploy.sh v1.0.1
```

`deploy.sh` does this, in order:

1. Pulls the two images for the tag.
2. Starts MySQL and Redis and waits until they are healthy.
3. Runs the migrations. If they fail, nothing is switched and the old version keeps running.
4. Starts the new containers.
5. Calls `/status` for up to 60 seconds.
6. Healthy: remembers the tag in `.current_tag`. Not healthy: starts the previous tag again and exits with an error.

I tested the script locally with three releases: a good v1, a good v2 and a deliberately broken v3 (its PHP container exits at start). v3 was detected as unhealthy and v2 was started again automatically.

The workflow's `deploy` job runs the same script over SSH. It is switched off until you add the repository variable `DEPLOY_ENABLED=true` and the secrets `DEPLOY_HOST`, `DEPLOY_USER` and `DEPLOY_SSH_KEY`. That job has not been run against a real server, because I don't have one for this demo.

## Tests

```bash
composer install && php artisan test
```

Five tests cover the status page (ok, version, heartbeat, database down gives 503), the redirect from `/`, and that the heartbeat is scheduled.

For the reasoning behind each choice, read [docs/HOW-IT-WORKS.md](docs/HOW-IT-WORKS.md).
