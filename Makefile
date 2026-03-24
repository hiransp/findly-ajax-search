PLUGIN_NAME = wc-custom-ajax-search

.PHONY: zip clean

zip: clean
	@mkdir -p build/$(PLUGIN_NAME)
	@cp -r assets includes LICENSE readme.txt uninstall.php wc-custom-ajax-search.php build/$(PLUGIN_NAME)/
	@cd build && zip -rq ../$(PLUGIN_NAME).zip $(PLUGIN_NAME)/
	@rm -rf build
	@echo "Created $(PLUGIN_NAME).zip"

clean:
	@rm -rf build $(PLUGIN_NAME).zip