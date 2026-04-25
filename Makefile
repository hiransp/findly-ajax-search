PLUGIN_NAME = wc-custom-ajax-search

.PHONY: zip clean pot

zip: clean
	@mkdir -p build/$(PLUGIN_NAME)
	@cp -r assets includes languages LICENSE readme.txt uninstall.php index.php wc-custom-ajax-search.php build/$(PLUGIN_NAME)/
	@cd build && zip -rq ../$(PLUGIN_NAME).zip $(PLUGIN_NAME)/
	@rm -rf build
	@echo "Created $(PLUGIN_NAME).zip"

pot:
	@xgettext --language=PHP --keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e --keyword=esc_attr__ --keyword=esc_attr_e --keyword=_n:1,2 --keyword=_x:1,2c --keyword=_nx:1,2,4c --from-code=UTF-8 --output=languages/wc-custom-ajax-search.pot --package-name="WC Custom AJAX Search" --package-version="1.0.0" wc-custom-ajax-search.php includes/*.php
	@echo "Generated languages/wc-custom-ajax-search.pot"

clean:
	@rm -rf build $(PLUGIN_NAME).zip
