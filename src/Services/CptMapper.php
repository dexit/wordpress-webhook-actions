<?php

namespace FlowSystems\WebhookActions\Services;

defined('ABSPATH') || exit;

/**
 * Creates or updates a WordPress Custom Post Type entry from a received payload.
 *
 * Configuration (cpt_config JSON):
 * {
 *   "post_type":       "my_cpt",
 *   "operation":       "create" | "upsert",
 *   "post_status":     "publish" | "draft" | "pending",
 *   "title_template":  "{{received.body.title}}",
 *   "content_template":"{{received.body.description}}",
 *   "lookup_meta_key": "_external_id",          // for upsert: meta key to match
 *   "lookup_template": "{{received.body.id}}",   // value to match for upsert
 *   "meta_mappings": [
 *     {"meta_key": "_external_id",  "template": "{{received.body.id}}"},
 *     {"meta_key": "_status",       "template": "{{received.body.status}}"},
 *     {"meta_key": "_raw_payload",  "template": "{{_flatten}}"}  // special: store whole flattened body
 *   ],
 *   "flatten_meta":    true   // auto-create meta key per flattened field
 * }
 *
 * Template tags are processed by TemplateRenderer.
 * Special template "{{_flatten}}" stores the entire flattened body as the meta value.
 */
class CptMapper {

  /**
   * Process the received payload and create/update a CPT post.
   *
   * @param array<string, mixed> $endpoint  Endpoint config row
   * @param array<string, mixed> $context   Template context (from TemplateRenderer::buildContext)
   * @return int|false  New/updated post ID or false on failure
   */
  public function process(array $endpoint, array $context): int|false {
    if (empty($endpoint['cpt_enabled'])) {
      return false;
    }

    $config = !empty($endpoint['cpt_config'])
      ? (json_decode($endpoint['cpt_config'], true) ?? [])
      : [];

    $postType  = sanitize_key($config['post_type'] ?? 'post');
    $operation = $config['operation'] ?? 'create'; // create | upsert
    $status    = $config['post_status'] ?? 'publish';
    $title     = TemplateRenderer::render($config['title_template'] ?? '', $context);
    $content   = TemplateRenderer::render($config['content_template'] ?? '', $context);

    // Resolve post for upsert
    $existingId = 0;
    if ($operation === 'upsert' && !empty($config['lookup_meta_key'])) {
      $lookupValue = TemplateRenderer::render($config['lookup_template'] ?? '', $context);
      if ($lookupValue !== '') {
        $existing = get_posts([
          'post_type'      => $postType,
          'post_status'    => 'any',
          'numberposts'    => 1,
          'meta_key'       => $config['lookup_meta_key'],    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
          'meta_value'     => $lookupValue,                  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
          'fields'         => 'ids',
        ]);
        $existingId = !empty($existing) ? (int) $existing[0] : 0;
      }
    }

    // Insert or update
    $postData = [
      'post_type'    => $postType,
      'post_status'  => $status,
      'post_title'   => $title ?: '(no title)',
      'post_content' => $content,
    ];

    if ($existingId > 0) {
      $postData['ID'] = $existingId;
      $postId         = wp_update_post($postData, true);
    } else {
      $postId = wp_insert_post($postData, true);
    }

    if (is_wp_error($postId)) {
      return false;
    }

    $postId = (int) $postId;

    // Save explicit meta mappings
    $metaMappings = $config['meta_mappings'] ?? [];
    foreach ($metaMappings as $mapping) {
      $metaKey = sanitize_key($mapping['meta_key'] ?? '');
      if (empty($metaKey)) {
        continue;
      }

      $tpl   = $mapping['template'] ?? '';
      $value = ($tpl === '{{_flatten}}')
        ? wp_json_encode(TemplateRenderer::flatten($context['received']['body'] ?? []))
        : TemplateRenderer::render($tpl, $context);

      update_post_meta($postId, $metaKey, $value);
    }

    // Auto-flatten: save every leaf of the body as a separate meta entry
    if (!empty($config['flatten_meta'])) {
      $flat   = TemplateRenderer::flatten($context['received']['body'] ?? []);
      $prefix = sanitize_key($config['flatten_meta_prefix'] ?? 'fswa_');
      foreach ($flat as $key => $value) {
        update_post_meta($postId, $prefix . $key, $value);
      }
    }

    /**
     * Fires after a CPT post has been created/updated from an incoming payload.
     *
     * @param int   $postId   The post ID.
     * @param array $endpoint Endpoint config.
     * @param array $context  Template context.
     */
    do_action('fswa_cpt_mapped', $postId, $endpoint, $context);

    return $postId;
  }
}
