.DEFAULT_GOAL := help

##help: List available tasks on this project
help:
	@echo ""
	@echo "These are the available commands"
	@echo ""
	@grep -E '\#\#[a-zA-Z\.\-]+:.*$$' $(MAKEFILE_LIST) \
        | tr -d '##' \
        | awk 'BEGIN {FS = ": "}; {printf "  \033[36m%-30s\033[0m %s\n", $$1, $$2}' \

##phpstan: run phpstan
phpstan:
	vendor/bin/phpstan analyse -c phpstan.dist.neon --memory-limit=1G
.PHONY: phpstan
