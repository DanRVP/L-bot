.PHONY: shell exec up down restart server test phpunit
default: server

# Capture all arguments after first word to pass into exec
ARGS := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))
$(eval $(ARGS):;@:)

# Get name of bref container
BREF_CONTAINER = $(or $(shell docker compose ps --format '{{.Name}}' | grep -m 1 "\-bref"), 'l-bot-bref')

## help:	Print commands help.
help: Makefile
	@sed -n 's/^##//p' $<

## shell:	Access the php container via shell.
shell:
	docker exec -ti -w /var/task -e COLUMNS=$(shell tput cols) -e LINES=$(shell tput lines) $(BREF_CONTAINER) bash

## exec:	Execute a command in the php container via shell.
exec:
	docker exec -ti -w /var/task $(BREF_CONTAINER) $(ARGS)

## phpunit:	Run unit tests
phpunit:
	$(MAKE) exec ARGS="vendor/bin/phpunit"

## up:	Shortcut for "docker compose up -d"
up:
	docker compose up -d

## down:	Shortcut for "docker compose down"
down:
	docker compose down

## restart:	Shortcut for "docker compose restart"
restart:
	docker compose restart

# https://stackoverflow.com/a/6273809/1826109
%:
	@:
