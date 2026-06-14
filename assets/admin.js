(function () {
	'use strict';

	function isObject(value) {
		return value !== null && typeof value === 'object' && !Array.isArray(value);
	}

	function findKey(value, key) {
		if (Array.isArray(value)) {
			return value.some(function (item) { return findKey(item, key); });
		}
		if (!isObject(value)) {
			return false;
		}
		if (Object.prototype.hasOwnProperty.call(value, key)) {
			return true;
		}
		return Object.keys(value).some(function (name) { return findKey(value[name], key); });
	}

	function extractScript(value) {
		var trimmed = value.trim();
		var openTag = '<' + 'script';
		var closeTag = '<' + '/script';

		if (trimmed.toLowerCase().indexOf(openTag) === -1 && trimmed.toLowerCase().indexOf(closeTag) === -1) {
			return { value: trimmed, removed: false };
		}

		var pattern = new RegExp('^\\s*<' + 'script\\b[^>]*\\btype\\s*=\\s*(["\\\'])application\\/ld\\+json\\1[^>]*>([\\s\\S]*)<' + '\\/script>\\s*$', 'i');
		var match = trimmed.match(pattern);
		if (!match) {
			throw new Error(SafeSchemaI18n.badWrapper);
		}
		return { value: match[2].trim(), removed: true };
	}

	function parse(textarea) {
		var extracted = extractScript(textarea.value);
		if (!extracted.value) {
			throw new Error(SafeSchemaI18n.empty);
		}

		var data = JSON.parse(extracted.value);
		if (data === null || typeof data !== 'object') {
			throw new Error(SafeSchemaI18n.root);
		}
		if (Array.isArray(data) && data.some(function (item) { return !isObject(item); })) {
			throw new Error(SafeSchemaI18n.root);
		}
		return { data: data, removed: extracted.removed };
	}

	function status(element, type, message) {
		element.className = 'safeschema-status is-' + type;
		element.textContent = message;
	}

	function getMessage(data, base) {
		var messages = [base];
		if (!findKey(data, '@context')) {
			messages.push(SafeSchemaI18n.missingContext);
		}
		if (!findKey(data, '@type') && !findKey(data, '@graph')) {
			messages.push(SafeSchemaI18n.missingType);
		}
		return messages.join(' ');
	}

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.querySelector('[data-safeschema]');
		if (!root) {
			return;
		}

		var textarea = root.querySelector('#safeschema_json_ld');
		var output = root.querySelector('[data-safeschema-status]');
		var warning = root.querySelector('[data-safeschema-replace-warning]');
		var radios = root.querySelectorAll('input[name="safeschema_mode"]');

		function updateMode() {
			var selected = root.querySelector('input[name="safeschema_mode"]:checked');
			warning.hidden = !selected || selected.value !== 'replace';
		}

		Array.prototype.forEach.call(radios, function (radio) {
			radio.addEventListener('change', updateMode);
		});
		updateMode();

		root.querySelector('[data-safeschema-validate]').addEventListener('click', function () {
			try {
				var parsed = parse(textarea);
				status(output, 'success', getMessage(parsed.data, SafeSchemaI18n.valid));
			} catch (error) {
				status(output, 'error', SafeSchemaI18n.invalid + ' ' + error.message);
			}
		});

		root.querySelector('[data-safeschema-format]').addEventListener('click', function () {
			try {
				var parsed = parse(textarea);
				textarea.value = JSON.stringify(parsed.data, null, 2);
				status(output, 'success', getMessage(parsed.data, parsed.removed ? SafeSchemaI18n.wrapperRemoved : SafeSchemaI18n.formatted));
			} catch (error) {
				status(output, 'error', SafeSchemaI18n.invalid + ' ' + error.message);
			}
		});
	});
}());
