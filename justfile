set dotenv-load := true

export UID := `id -u`
export GID := `id -g`
export COMPOSE_PROFILES := if env_var_or_default("CI", "0") == "true" { "test" } else { "dev" }

COMPOSE := 'docker compose -f docker/app.yml -p ' + env_var('PROJECT_NAME')
COMPOSE-RUN := COMPOSE + ' run --rm'
PHP-DB-RUN := COMPOSE-RUN + ' php'
PHP-RUN := COMPOSE-RUN + ' --no-deps php'
MARIADB-RUN := COMPOSE-RUN + ' -T --no-deps mariadb'

default:
	just --list

init: start
	rm -f config.php
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb --skip-ssl drop flarum --force || true
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb --skip-ssl create flarum
	{{PHP-DB-RUN}} php flarum install -f docker/install.yml
	{{PHP-DB-RUN}} php flarum app:enable-extensions
	{{PHP-DB-RUN}} php flarum migrate
	{{PHP-DB-RUN}} php flarum assets:publish
	{{PHP-DB-RUN}} php flarum cache:clear

start:
	{{COMPOSE}} up -d
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb --skip-ssl --wait=10 ping
	@echo URL: http://localhost:${PORT}

start-db:
	{{COMPOSE}} up -d mariadb
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb --skip-ssl --wait=10 ping

stop:
	{{COMPOSE}} stop

# Load a (gzipped) database backup for local testing
import-db-dump file name='flarum': start
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb --skip-ssl drop -f {{name}} || true
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb --skip-ssl create {{name}}
	zcat {{file}} | {{MARIADB-RUN}} mariadb -uroot -hmariadb --skip-ssl {{name}}
	{{PHP-DB-RUN}} php flarum app:enable-extensions
	{{PHP-DB-RUN}} php flarum migrate
	{{PHP-DB-RUN}} php flarum assets:publish
	{{PHP-DB-RUN}} php flarum cache:clear

# Merge older image-backup archives into storage/image-backup, keeping existing files
# unless they are known placeholders. Process oldest archives first.
import-image-backups *archives:
	#!/usr/bin/env bash
	set -e
	BACKUP_DIR="storage/image-backup"
	PLACEHOLDER_HASHES="5af0b5eac2f726754f7423d280c271b6980ae042 85c76fb58166cf4de3275b6c73773b974ad2b94e 38ca219048e780e37af31d1348c441dd5fce26a6 20002faf28adfd94ca98cf6ced46f14334b53684 f4ce39693c3342011c11c4b53d7b13119ed2bb3c 4dcb57651a75abfd07fb36c70c6c5108c49bdb34 54f42faf8543d7f31fc3983af9b2f9da3b2dbb4c 2f14306594f10d7a085618d68492c713cd7795f3 1b9747adc89e8198cd9f1d0a437f30a2779e6f1d"
	TMPDIR=$(mktemp -d)
	trap 'rm -rf "$TMPDIR"' EXIT
	added=0
	replaced=0
	skipped=0
	for archive in $(echo {{archives}} | tr ' ' '\n' | sort); do
		echo "=== Processing: $(basename "$archive") ==="
		tar xzf "$archive" -C "$TMPDIR"
		# Detect archive path format: storage/image-backup/ or image-backup/
		if [ -d "$TMPDIR/storage/image-backup" ]; then
			src_root="$TMPDIR/storage/image-backup"
		elif [ -d "$TMPDIR/image-backup" ]; then
			src_root="$TMPDIR/image-backup"
		else
			echo "  SKIP: no image-backup directory found in archive"
			continue
		fi
		while IFS= read -r -d '' src; do
			rel="${src#$src_root/}"
			dst="$BACKUP_DIR/$rel"
			if [ ! -f "$dst" ]; then
				mkdir -p "$(dirname "$dst")"
				cp -p "$src" "$dst"
				echo "  ADD: $rel"
				added=$((added + 1))
			else
				src_size=$(stat -c%s "$src")
				dst_size=$(stat -c%s "$dst")
				dst_hash=$(sha1sum "$dst" | cut -d' ' -f1)
				if echo "$PLACEHOLDER_HASHES" | grep -qw "$dst_hash"; then
					src_hash=$(sha1sum "$src" | cut -d' ' -f1)
					if ! echo "$PLACEHOLDER_HASHES" | grep -qw "$src_hash"; then
						cp -p "$src" "$dst"
						echo "  REPLACE: $rel (was placeholder)"
						replaced=$((replaced + 1))
					else
						skipped=$((skipped + 1))
					fi
				elif [ "$src_size" -gt "$dst_size" ]; then
					src_hash=$(sha1sum "$src" | cut -d' ' -f1)
					if echo "$PLACEHOLDER_HASHES" | grep -qw "$src_hash"; then
						skipped=$((skipped + 1))
					else
						cp -p "$src" "$dst"
						echo "  UPGRADE: $rel ($dst_size -> $src_size bytes)"
						replaced=$((replaced + 1))
					fi
				else
					skipped=$((skipped + 1))
				fi
			fi
		done < <(find "$src_root" -type f -not -name 'failed.log' -print0)
		rm -rf "$TMPDIR/storage" "$TMPDIR/image-backup"
	done
	echo ""
	echo "=== Summary ==="
	echo "Added: $added"
	echo "Replaced: $replaced"
	echo "Skipped: $skipped"

# Restore paste.archlinux.de images from the filebin backup into storage/image-backup.
# Requires: filebin.sql.gz (DB dump) and uploads.tar.gz (file storage) in the project root.
import-paste-backup: start-db
	#!/usr/bin/env bash
	set -e
	BACKUP_DIR="storage/image-backup/paste.archlinux.de"
	TMPDIR=$(mktemp -d)
	trap 'rm -rf "$TMPDIR"' EXIT

	echo "Loading filebin database..."
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb --skip-ssl drop -f filebin 2>/dev/null || true
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb --skip-ssl create filebin
	zcat filebin.sql.gz | {{MARIADB-RUN}} mariadb -uroot -hmariadb --skip-ssl filebin

	echo "Extracting uploads archive..."
	tar xzf uploads.tar.gz -C "$TMPDIR"

	echo "Querying forum posts for paste.archlinux.de image URLs..."
	# Extract all slugs from forum posts that use paste.archlinux.de in IMG tags.
	# Uses a recursive CTE to find every occurrence per post (SUBSTRING_INDEX
	# with -1 would only return the last one).
	slugs=$({{MARIADB-RUN}} mariadb -uroot -hmariadb --skip-ssl -N flarum -e "
		WITH RECURSIVE matches AS (
			SELECT LOCATE('paste.archlinux.de/', content) AS pos, content
			FROM posts
			WHERE content LIKE '%<IMG%paste.archlinux.de/%'
			UNION ALL
			SELECT LOCATE('paste.archlinux.de/', content, pos + 19), content
			FROM matches
			WHERE LOCATE('paste.archlinux.de/', content, pos + 19) > 0
		)
		SELECT DISTINCT REGEXP_SUBSTR(
			SUBSTRING(content, pos + 19),
			'^[A-Za-z0-9]+')
		FROM matches
	" | grep -v '^$')

	echo "Found slugs: $slugs"

	added=0
	skipped=0
	missing=0
	for slug in $slugs; do
		# Look up file hash and storage ID from filebin DB
		row=$({{MARIADB-RUN}} mariadb -uroot -hmariadb --skip-ssl -N filebin -e "
			SELECT fs.hash, fs.id
			FROM files f
			JOIN file_storage fs ON fs.id = f.file_storage_id
			WHERE f.id = '$slug'
		")
		if [ -z "$row" ]; then
			echo "  NOT IN DB: $slug"
			missing=$((missing + 1))
			continue
		fi
		hash=$(echo "$row" | awk '{print $1}')
		sid=$(echo "$row" | awk '{print $2}')
		prefix=${hash:0:3}
		src="$TMPDIR/uploads/$prefix/$hash-$sid"
		dst="$BACKUP_DIR/$slug"

		if [ -f "$dst" ]; then
			echo "  EXISTS: $slug"
			skipped=$((skipped + 1))
			continue
		fi

		if [ ! -f "$src" ]; then
			echo "  FILE MISSING: $slug ($src)"
			missing=$((missing + 1))
			continue
		fi

		mkdir -p "$BACKUP_DIR"
		cp -p "$src" "$dst"
		echo "  ADD: $slug"
		added=$((added + 1))
	done

	echo ""
	echo "=== Summary ==="
	echo "Added: $added"
	echo "Skipped: $skipped"
	echo "Missing: $missing"

	# Clean up filebin database
	{{MARIADB-RUN}} mariadb-admin -uroot -hmariadb --skip-ssl drop -f filebin

# Import external images into fof/upload.
# Expects image-backup-*.tar.gz archives in the project root.
# After verifying, remove storage/image-backup/, the tar.gz archives,
# BackupImages.php, ImportImages.php, their extend.php registrations,
# and the import-image-backups/import-paste-backup/deploy-import-images recipes.
deploy-import-images:
	#!/usr/bin/env bash
	set -e
	echo "=== Merging backup archives ==="
	just import-image-backups image-backup-*.tar.gz
	echo ""
	echo "=== Dry run ==="
	./flarum app:import-images --dry-run
	echo ""
	read -p "Proceed with import? [y/N] " confirm
	if [ "$confirm" != "y" ]; then
		echo "Aborted."
		exit 1
	fi
	echo ""
	echo "=== Importing images ==="
	./flarum app:import-images
	echo ""
	echo "=== Fixing permissions ==="
	just deploy-permissions
	echo ""
	echo "=== Done ==="
	echo "Verify the forum, then clean up:"
	echo "  rm -rf storage/image-backup/ image-backup-*.tar.gz"

# Load avatars created with "tar cvzf forum-avatars.tar.gz /srv/http/vhosts/forum.archlinux.de/public/assets/avatars/*.*"
import-avatars file:
	tar -x --strip-components 6 -f {{file}} -C public/assets/

clean:
	{{COMPOSE}} rm -vsf
	git clean -fdqx -e .idea

rebuild: clean
	{{COMPOSE}} -f docker/cypress-run.yml -f docker/cypress-open.yml build --pull
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

phpstan *args:
	{{PHP-RUN}} php -dmemory_limit=-1 vendor/bin/phpstan {{args}}

flarum *args:
	{{PHP-RUN}} php flarum {{args}}

cypress *args:
	{{COMPOSE}} -f docker/cypress-run.yml run --rm --no-deps --entrypoint cypress cypress-run {{args}}

cypress-run *args:
	{{COMPOSE}} -f docker/cypress-run.yml run --rm --no-deps cypress-run --headless --browser chrome --project tests/e2e {{args}}

cypress-open *args:
	Xephyr :${PORT} -screen 1920x1080 -resizeable -name Cypress -title "Cypress - {{ env_var('PROJECT_NAME') }}" -terminate -no-host-grab -extension MIT-SHM -extension XTEST -nolisten tcp &
	DISPLAY=:${PORT} DISPLAY_SOCKET=/tmp/.X11-unix/X${PORT%%:*} {{COMPOSE}} -f docker/cypress-open.yml run --rm --no-deps cypress-open --project tests/e2e --e2e {{args}}

test:
	{{PHP-RUN}} composer validate
	{{PHP-RUN}} vendor/bin/phpcs
	{{PHP-RUN}} php -dmemory_limit=-1 vendor/bin/phpstan analyse

test-e2e:
	#!/usr/bin/env bash
	set -e
	if [ "${CI-}" = "true" ]; then
		just init
		CYPRESS_baseUrl=http://nginx:8080 just cypress-run
	else
		just cypress-run
	fi

update:
	{{PHP-RUN}} composer --no-interaction update
	{{PHP-RUN}} composer --no-interaction update --lock --no-scripts

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
