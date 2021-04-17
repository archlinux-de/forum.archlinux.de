export UID := `id -u`
export GID := `id -g`

COMPOSE := 'docker-compose -f docker/app.yml ' + `[ "${CI-}" != "true" ] && echo '-f docker/dev.yml' || echo ''` + ' -p ' + env_var('PROJECT_NAME')
COMPOSE-RUN := COMPOSE + ' run --rm'
PHP-DB-RUN := COMPOSE-RUN + ' php'
PHP-RUN := COMPOSE-RUN + ' --no-deps php'
MARIADB-RUN := COMPOSE-RUN + ' --no-deps mariadb'

default:
	just --list

init: start
	rm -f config.php
	{{MARIADB-RUN}} mysqladmin -uroot -hmariadb drop flarum --force || true
	{{MARIADB-RUN}} mysqladmin -uroot -hmariadb create flarum
	{{PHP-DB-RUN}} php flarum install -f docker/install.yml
	{{PHP-DB-RUN}} php flarum app:enable-extensions
	{{PHP-DB-RUN}} php flarum migrate
	{{PHP-DB-RUN}} php flarum cache:clear

start:
	{{COMPOSE}} up -d
	{{MARIADB-RUN}} mysqladmin -uroot -hmariadb --wait=10 ping
	@echo URL: http://localhost:${PORT}

start-db:
	{{COMPOSE}} up -d mariadb
	{{MARIADB-RUN}} mysqladmin -uroot -hmariadb --wait=10 ping

stop:
	{{COMPOSE}} stop

# Load a (gzipped) database backup for local testing
import-db-dump file name='flarum': start
	{{MARIADB-RUN}} mysqladmin -uroot -hmariadb drop -f {{name}} || true
	{{MARIADB-RUN}} mysqladmin -uroot -hmariadb create {{name}}
	zcat {{file}} | {{MARIADB-RUN}} mysql -uroot -hmariadb {{name}}

import-from-fluxbb-db-dump file:
	just import-db-dump {{file}} fluxbb
	{{PHP-DB-RUN}} php flarum app:import-from-fluxbb -vvv
	{{PHP-DB-RUN}} php flarum cache:clear

clean:
	{{COMPOSE}} down -v
	git clean -fdqx -e .idea

rebuild: clean
	{{COMPOSE}} build --pull --parallel
	just install
	just init
	just stop

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

test:
	{{PHP-RUN}} composer validate

# vim: set ft=make :
