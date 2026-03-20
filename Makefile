PLUGIN_NAME = wc-custom-ajax-search

.PHONY: zip clean

zip: clean
	@mkdir -p build/$(PLUGIN_NAME)
	@git ls-files | grep -v -F -f .distignore | while read f; do \
		mkdir -p "build/$(PLUGIN_NAME)/$$(dirname "$$f")"; \
		cp "$$f" "build/$(PLUGIN_NAME)/$$f"; \
	done
	@cd build && zip -rq ../$(PLUGIN_NAME).zip $(PLUGIN_NAME)/
	@rm -rf build
	@echo "Created $(PLUGIN_NAME).zip"

clean:
	@rm -rf build $(PLUGIN_NAME).zip
