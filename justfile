set dotenv-load := true

export UID := `id -u`
export GID := `id -g`

COMPOSE := 'docker compose -f docker/app.yml ' + `[ "${CI-}" != "true" ] && echo '-f docker/dev.yml' || echo ''` + ' -p ' + env_var('PROJECT_NAME')
COMPOSE-RUN := COMPOSE + ' run --rm'
PHP-DB-RUN := COMPOSE-RUN + ' php'
PHP-RUN := COMPOSE-RUN + ' --no-deps php'
MARIADB-RUN := COMPOSE-RUN + ' -T --no-deps mariadb'

default:
	just --list

init: start
	rm -f config.php
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb drop flarum --force || true
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb create flarum
	{{PHP-DB-RUN}} php flarum install -f docker/install.yml
	{{PHP-DB-RUN}} php flarum app:enable-extensions
	{{PHP-DB-RUN}} php flarum migrate
	{{PHP-DB-RUN}} php flarum assets:publish
	{{PHP-DB-RUN}} php flarum cache:clear

start:
	{{COMPOSE}} up -d
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb --wait=10 ping
	@echo URL: http://localhost:${PORT}

start-db:
	{{COMPOSE}} up -d mariadb
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb --wait=10 ping

stop:
	{{COMPOSE}} stop

# Load a (gzipped) database backup for local testing
import-db-dump file name='flarum': start
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb drop -f {{name}} || true
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb create {{name}}
	zcat {{file}} | {{MARIADB-RUN}} mariadb -uroot -hmariadb {{name}}

# Load abatars created with "tar cvzf forum-avatars.tar.gz /srv/http/vhosts/forum.archlinux.de/public/assets/avatars/*.*"
import-avatars file:
	tar -x --strip-components 6 -f {{file}} -C public/assets/

clean:
	{{COMPOSE}} rm -vsf
	git clean -fdqx -e .idea

rebuild: clean
	{{COMPOSE}} build --pull
	just install
	just init

install:
	{{PHP-RUN}} composer --no-interaction install

compose *args:
	{{COMPOSE}} {{args}}

compose-run *args:
	{{COMPOSE-RUN}} {{args}}

php *args='-h':
	{{PHP-RUN}} php {{args}}

composer *args:
	{{PHP-RUN}} composer {{args}}

composer-outdated: (composer "install") (composer "outdated --direct --strict")

flarum *args:
	{{PHP-RUN}} php flarum {{args}}

cypress-run *args:
	{{COMPOSE}} -f docker/cypress-run.yml run     --rm --no-deps cypress run  --project tests/e2e --browser chrome --headless {{args}}

cypress-open:
	xhost +local:root
	{{COMPOSE}} -f docker/cypress-open.yml run -d --rm --no-deps cypress open --project tests/e2e

test:
	{{PHP-RUN}} composer validate
	{{PHP-RUN}} vendor/bin/phpcs
	{{PHP-RUN}} php -dmemory_limit=-1 vendor/bin/phpstan analyse

test-e2e:
	#!/usr/bin/env bash
	set -e
	if [ "${CI-}" = "true" ]; then
		just init
	fi
	just cypress-run

_update-cypress-image:
	#!/usr/bin/env bash
	set -e
	CYPRESS_VERSION=$(curl -sSf 'https://hub.docker.com/v2/repositories/cypress/included/tags/?page_size=1' | jq -r '."results"[]["name"]')
	sed -E "s#(cypress/included:)[0-9.]+#\1${CYPRESS_VERSION}#g" -i docker/cypress-*.yml

update:
	{{PHP-RUN}} composer --no-interaction update
	{{PHP-RUN}} composer --no-interaction update --lock --no-scripts
	just _update-cypress-image

deploy:
	composer --no-interaction install --prefer-dist --no-dev --optimize-autoloader --classmap-authoritative
	./flarum app:enable-extensions
	./flarum migrate
	./flarum assets:publish
	./flarum cache:clear
	systemctl restart php-fpm@forum.service
	./flarum cache:clear

deploy-permissions:
	sudo setfacl -dR -m u:php-forum:rwX -m u:deployer:rwX storage public/assets
	sudo setfacl -R -m u:php-forum:rwX -m u:deployer:rwX storage public/assets
	sudo setfacl -d -m u:php-forum:rwX -m u:deployer:rwX public
	sudo setfacl -m u:php-forum:rwX -m u:deployer:rwX public
	sudo setfacl -m u:php-forum:rw -m u:deployer:rw public/feed.xml

# vim: set ft=make :
