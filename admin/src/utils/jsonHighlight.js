// Syntax-highlighted JSON for the payload previews.
//
// Lives here rather than in MappingEditor because every stage of the pipeline
// renders one of these — original payload, after mapping, after pre-dispatch
// Code Glue — and they should look identical wherever they appear.
//
// Output is injected with v-html, so escaping is not optional: payloads
// routinely carry HTML (an upstream response body on a chain-link trigger,
// post_content from WordPress).

const escapeHtml = (str) => String(str)
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')
  .replace(/'/g, '&#39;');

export const formatJsonWithHighlight = (obj, indent = 0) => {
  if (obj === null) return '<span class="text-orange-500">null</span>';
  if (obj === undefined) return '<span class="text-gray-400">undefined</span>';

  const indentStr = '  '.repeat(indent);
  const nextIndent = '  '.repeat(indent + 1);

  if (typeof obj === 'string') {
    // Escape HTML BEFORE JSON-escaping the visible quotes/newlines, so that
    // user payloads containing literal HTML render as text instead of
    // injecting tags through v-html.
    const escaped = escapeHtml(obj).replace(/"/g, '\\"').replace(/\n/g, '\\n');

    return `<span class="text-green-600 dark:text-green-400">"${escaped}"</span>`;
  }

  if (typeof obj === 'number') {
    return `<span class="text-blue-600 dark:text-blue-400">${obj}</span>`;
  }

  if (typeof obj === 'boolean') {
    return `<span class="text-purple-600 dark:text-purple-400">${obj}</span>`;
  }

  if (Array.isArray(obj)) {
    if (obj.length === 0) return '[]';
    const items = obj
      .map(
        (item) => `${nextIndent}${formatJsonWithHighlight(item, indent + 1)}`,
      );

    return `[\n${items.join(',\n')}\n${indentStr}]`;
  }

  if (typeof obj === 'object') {
    const keys = Object.keys(obj);
    if (keys.length === 0) return '{}';
    const entries = keys.map((key) => {
      const value = formatJsonWithHighlight(obj[key], indent + 1);
      return `${nextIndent}<span class="text-red-600 dark:text-red-400">"${escapeHtml(key)}"</span>: ${value}`;
    });

    return `{\n${entries.join(',\n')}\n${indentStr}}`;
  }

  return escapeHtml(obj);
};
