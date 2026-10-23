# How shipkit works

Read this before an interview. It explains the project in plain words.

## The idea

"It works on my machine" is not a release. This project shows the path from code to a running server: build an image once, test the same image everywhere, deploy by tag, check it is healthy, and go back if it is not.

## Containers and what each one does

| Container | Image | Job |
|---|---|---|
| `web` | nginx + the `public/` folder | Takes HTTP requests. Serves static files itself and passes `.php` requests to `app`. |
| `app` | PHP-FPM with the Laravel code | Runs the PHP for each request. |
| `worker` | same image as `app` | Runs `php artisan queue:work`. Takes jobs from Redis. |
| `scheduler` | same image as `app` | Runs `php artisan schedule:work`. Once a minute it asks "what is due?" and queues it. |
| `mysql` | mysql:8.4 | The database. Data lives in a named volume so it survives restarts. |
| `redis` | redis:7 | Queue and cache. |
| `migrate` (local only) | same image as `app` | Runs the migrations once, then exits. Others wait for it. |

Worker and scheduler use the **same image** as the web app with a different command. One build, three roles. This is the usual pattern.

## The Dockerfile

Three stages:

1. **vendor** uses the official Composer image to install dependencies without dev packages. Layer order matters: `composer.json` and `composer.lock` are copied first, so the install layer is cached until dependencies change.
2. **app** starts from `php:8.3-fpm-alpine`, adds the PHP extensions the app needs (`pdo_mysql`, `redis`, `opcache`), copies the code and vendor folder in, and runs as the unprivileged `www-data` user.
3. **web** is nginx plus a copy of `public/` taken from the `app` stage. Nginx needs the static files, PHP-FPM needs the code. They are two containers, so each gets what it needs.

The entry script runs `config:cache`, `route:cache` and `view:cache` when the container starts, so the caches are built from the real environment variables of that container, not from the build machine.

`.dockerignore` keeps `.env`, `vendor`, tests and git history out of the image. It also excludes `bootstrap/cache/*.php`. I learned that one the hard way: my laptop's package cache listed a dev-only package, and the production image crashed at start because that package isn't installed there.

## The scheduler, the queue and the heartbeat

`routes/console.php` schedules `RecordHeartbeat` every minute. The scheduler container queues it into Redis. The worker runs it and writes the current time into the cache. `/status` reads that time. If the scheduler or the worker is dead, the number stops moving. So one number checks three containers plus Redis.

## CI and release

- **CI** (every push and pull request): code style (Pint), tests, and a build of both images so a broken Dockerfile is caught before release. Builds use the GitHub Actions cache to stay fast.
- **Release** (when you push a tag like `v1.0.1`): builds both images and pushes them to GitHub Container Registry with the tag as the image tag. Authentication uses the built-in `GITHUB_TOKEN`, no personal tokens needed. The tag is also passed in as `APP_VERSION`, so `/status` tells you what is running.
- Deploying by **immutable tag** (not `latest`) means you always know what is running and can go back to an exact earlier version.

## The deploy script, step by step

See the README for the list. Points worth explaining:

- **Migrations before switching.** If they fail, the old release keeps serving.
- **Health check.** Starting a container is not the same as the app working. Calling `/status` checks nginx, PHP, the database and the cache together.
- **Automatic rollback** starts the previous tag again. It does not roll back the database. That is why migrations should be **backward compatible**: add columns first, deploy, and only remove old columns in a later release (expand and contract). Otherwise the old code could break on the new schema.
- The previous tag is remembered in `.current_tag`.
- `--wait` on MySQL and Redis makes the script wait until they are healthy, so the migrate step doesn't race the database.

## Questions you may get

**Why two images and not one container with nginx and PHP together?** One process per container is easier to scale, restart and monitor. A single image with a process manager works, but hides failures.

**Why not use `latest`?** You can't tell what is running or which version to go back to.

**How would you do zero downtime?** The current script recreates containers, which has a short gap. Options: run two copies behind a load balancer and switch one at a time, or use blue/green with nginx pointing at either set. For one server, `docker compose up -d` is quick, and I would say so honestly.

**Where do secrets live?** In `.env.prod` on the server, outside git and outside the image. In CI, in GitHub secrets.

**How would you add a staging environment?** Same compose file with a different `PROJECT`, `PORT` and `.env.prod`, deployed from a branch or tag pattern.

**What does the deploy job in the workflow do and did you test it?** It runs `deploy.sh` over SSH. I tested the script locally against real containers (good release, upgrade, broken release with rollback). The SSH job has never run against a server, and I say so in the README.

**What would you add next?** Database backups before migrations, Slack or email on failed deploys, container resource limits, image scanning in CI, and log shipping.

## Try it yourself

Run the stack, open `/status`, then stop the worker (`docker compose stop worker`) and watch the heartbeat number grow. Start it again and watch it reset. Then read `deploy/deploy.sh` top to bottom; it is about 60 lines.
