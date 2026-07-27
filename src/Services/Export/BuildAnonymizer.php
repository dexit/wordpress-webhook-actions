<?php

namespace FlowSystems\WebhookActions\Services\Export;

defined('ABSPATH') || exit;

/**
 * Strips this site's own address out of a finished build document, for builds
 * that are shared publicly rather than moved between sites you own.
 *
 * The site URL leaks into more places than the obvious one: the export meta,
 * endpoint URLs of internal WP REST automations, header/param values, Code Glue
 * code, and the prose of a shared Build-with-AI transcript. So this runs over
 * the whole document — after extensions have attached their blocks — replacing
 * every occurrence of the site's URL or bare host with a reserved placeholder
 * (RFC 2606 `example.com`), which keeps endpoints valid http(s) URLs so the
 * document still imports; the importer re-points them at their own site.
 */
class BuildAnonymizer {
  /** Reserved-by-RFC replacement, so an anonymized endpoint stays a valid URL. */
  public const PLACEHOLDER_ORIGIN = 'https://example.com';
  public const PLACEHOLDER_HOST   = 'example.com';

  /**
   * @param array<string, mixed> $doc A complete export document.
   * @return array<string, mixed>
   */
  public function anonymize(array $doc): array {
    $doc = $this->replaceIn($doc);

    // The source site is pure provenance — drop it rather than fake it.
    if (isset($doc['fswa_export']) && is_array($doc['fswa_export'])) {
      $doc['fswa_export']['source_site'] = null;
    }

    return $doc;
  }

  /**
   * Recursively rewrite every string value in the document.
   *
   * @param mixed $value
   * @return mixed
   */
  private function replaceIn($value) {
    if (is_array($value)) {
      $out = [];
      foreach ($value as $key => $item) {
        $out[$key] = $this->replaceIn($item);
      }
      return $out;
    }

    return is_string($value) ? $this->scrub($value) : $value;
  }

  private function scrub(string $text): string {
    if ($text === '') {
      return $text;
    }

    foreach ($this->urlPatterns() as $pattern => $replacement) {
      $text = (string) preg_replace($pattern, $replacement, $text);
    }

    return $text;
  }

  /**
   * Patterns ordered longest-match-first: full URLs, then protocol-relative
   * forms, then the bare host (guarded so it can't match inside a longer
   * hostname such as `notexample.com`).
   *
   * @return array<string, string> pattern => replacement
   */
  private function urlPatterns(): array {
    $urls  = [];
    $hosts = [];

    $candidates = [home_url(), site_url()];
    if (is_multisite()) {
      $candidates[] = network_home_url();
      $candidates[] = network_site_url();
    }

    foreach ($candidates as $url) {
      $url = untrailingslashit((string) $url);
      if ($url === '') {
        continue;
      }
      $parts = wp_parse_url($url);
      $host  = (string) ($parts['host'] ?? '');
      if ($host === '') {
        continue;
      }
      if (!empty($parts['port'])) {
        $host .= ':' . $parts['port'];
      }
      $path = untrailingslashit((string) ($parts['path'] ?? ''));

      // Match either scheme, so an http:// mention of an https:// site is caught.
      $urls[$host . $path]  = '(?:https?:)?\/\/' . preg_quote($host . $path, '/');
      $hosts[$host]         = preg_quote($host, '/');
    }

    // Longest first: `site.test/blog` must win over `site.test`.
    uksort($urls, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    $patterns = [];
    foreach ($urls as $quoted) {
      $patterns['/' . $quoted . '/i'] = self::PLACEHOLDER_ORIGIN;
    }
    foreach ($hosts as $quoted) {
      // Not preceded by a hostname character (so `sub.site.test` is left alone)
      // and not followed by one (so `site.testing` is left alone).
      $patterns['/(?<![A-Za-z0-9.\-])' . $quoted . '(?![A-Za-z0-9\-])/i'] = self::PLACEHOLDER_HOST;
    }

    return $patterns;
  }
}
