<?php
if (!defined('ABSPATH')) exit;

class SDA_Ajax {

    public static function init() {
        $actions = [
            'sda_get_filter_options',
            'sda_get_sub_options',
            'sda_filter_users',
            'sda_get_user_orders',
            'sda_get_chart_data',
            'sda_load_groups',
            'sda_save_groups',
        ];
        foreach ($actions as $action) {
            add_action("wp_ajax_{$action}", [__CLASS__, $action]);
        }
    }

    private static function verify() {
        if (!current_user_can('manage_options') || !check_ajax_referer('sda_nonce', 'nonce', false)) {
            wp_send_json_error('Unauthorized', 403);
        }
    }

    /**
     * 讀取已儲存的篩選群組
     */
    public static function sda_load_groups() {
        self::verify();
        $groups = get_option('sda_filter_groups', []);
        wp_send_json_success(is_array($groups) ? $groups : []);
    }

    /**
     * 儲存篩選群組
     */
    public static function sda_save_groups() {
        self::verify();
        $groups = json_decode(stripslashes($_POST['groups'] ?? '[]'), true);
        if (!is_array($groups)) {
            wp_send_json_error('Invalid data');
        }
        // 最多5組
        $groups = array_slice($groups, 0, 5);
        update_option('sda_filter_groups', $groups, false);
        wp_send_json_success(true);
    }

    /**
     * 取得所有篩選選項的初始資料
     */
    public static function sda_get_filter_options() {
        self::verify();
        global $wpdb;
        $prefix = $wpdb->prefix;

        // 職業第一層
        $job_class_1 = $wpdb->get_results("SELECT id, class_text FROM {$prefix}job_class_1 ORDER BY id");

        // 報考類科第一層
        $test_class_1 = $wpdb->get_results("SELECT id, class_text FROM {$prefix}test_class_1 ORDER BY id");

        // 得知管道、購買原因、課程用途
        $source_options = maybe_unserialize(get_option('myform_source_options', ''));
        $reason_options = maybe_unserialize(get_option('myform_reason_options', ''));
        $usage_options  = maybe_unserialize(get_option('myform_usage_options', ''));

        wp_send_json_success([
            'job_class_1'    => $job_class_1,
            'test_class_1'   => $test_class_1,
            'source_options' => is_array($source_options) ? $source_options : [],
            'reason_options' => is_array($reason_options) ? $reason_options : [],
            'usage_options'  => is_array($usage_options) ? $usage_options : [],
        ]);
    }

    /**
     * 取得子層選項（職業第二層、報考類科第二/三層）
     */
    public static function sda_get_sub_options() {
        self::verify();
        global $wpdb;
        $prefix = $wpdb->prefix;

        $type      = sanitize_text_field($_POST['type'] ?? '');
        $parent_id = intval($_POST['parent_id'] ?? 0);

        $table_map = [
            'job_class_2'  => "{$prefix}job_class_2",
            'test_class_2' => "{$prefix}test_class_2",
            'test_class_3' => "{$prefix}test_class_3",
        ];

        if (!isset($table_map[$type])) {
            wp_send_json_error('Invalid type');
        }

        $table = $table_map[$type];
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT id, class_text FROM {$table} WHERE upper_class_id = %d ORDER BY id",
            $parent_id
        ));

        wp_send_json_success($results);
    }

    /**
     * 根據篩選條件過濾使用者
     */
    public static function sda_filter_users() {
        self::verify();
        global $wpdb;
        $prefix = $wpdb->prefix;

        $filters = json_decode(stripslashes($_POST['filters'] ?? '{}'), true);
        if (!is_array($filters)) {
            wp_send_json_error('Invalid filters');
        }

        $where = [];
        $join  = '';

        // 性別
        if (!empty($filters['gender'])) {
            $escaped = array_map(function($v) use ($wpdb) {
                return $wpdb->prepare('%s', $v);
            }, $filters['gender']);
            $where[] = "m.gender IN (" . implode(',', $escaped) . ")";
        }

        // 年齡
        if (!empty($filters['age'])) {
            $age_conditions = [];
            foreach ($filters['age'] as $range) {
                $parts = explode('-', $range);
                if (count($parts) === 2) {
                    $age_conditions[] = $wpdb->prepare("(m.age >= %d AND m.age <= %d)", intval($parts[0]), intval($parts[1]));
                }
            }
            if ($age_conditions) {
                $where[] = '(' . implode(' OR ', $age_conditions) . ')';
            }
        }

        // 職業（第二層直接比對，第一層透過子查詢或直接比對）
        $job_conditions = [];
        if (!empty($filters['job'])) {
            $escaped = array_map('intval', $filters['job']);
            $job_conditions[] = "m.job_2 IN (" . implode(',', $escaped) . ")";
        }
        if (!empty($filters['job_1'])) {
            $escaped1 = array_map('intval', $filters['job_1']);
            $in1 = implode(',', $escaped1);
            $job_conditions[] = "m.job_2 IN (SELECT id FROM {$prefix}job_class_2 WHERE upper_class_id IN ({$in1}))";
            $job_conditions[] = "m.job_2 IN ({$in1})";
        }
        if ($job_conditions) {
            $where[] = '(' . implode(' OR ', $job_conditions) . ')';
        }

        // 報考類科（第三層直接比對，第二層/第一層透過子查詢或直接比對）
        $test_conditions = [];
        if (!empty($filters['test'])) {
            $escaped = array_map('intval', $filters['test']);
            $test_conditions[] = "m.test_3 IN (" . implode(',', $escaped) . ")";
        }
        if (!empty($filters['test_2'])) {
            $escaped2 = array_map('intval', $filters['test_2']);
            $in2 = implode(',', $escaped2);
            $test_conditions[] = "m.test_3 IN (SELECT id FROM {$prefix}test_class_3 WHERE upper_class_id IN ({$in2}))";
            $test_conditions[] = "m.test_3 IN ({$in2})";
        }
        if (!empty($filters['test_1'])) {
            $escaped1 = array_map('intval', $filters['test_1']);
            $in1 = implode(',', $escaped1);
            $test_conditions[] = "m.test_3 IN (SELECT t3.id FROM {$prefix}test_class_3 t3 INNER JOIN {$prefix}test_class_2 t2 ON t3.upper_class_id = t2.id WHERE t2.upper_class_id IN ({$in1}))";
            $test_conditions[] = "m.test_3 IN ({$in1})";
        }
        if ($test_conditions) {
            $where[] = '(' . implode(' OR ', $test_conditions) . ')';
        }

        // 縣市
        if (!empty($filters['city'])) {
            $city_conditions = [];
            foreach ($filters['city'] as $item) {
                if (!empty($item['area'])) {
                    $city_conditions[] = $wpdb->prepare(
                        "(m.contAddrCity = %s AND m.contAddrArea = %s)",
                        $item['city'], $item['area']
                    );
                } else {
                    $city_conditions[] = $wpdb->prepare("m.contAddrCity = %s", $item['city']);
                }
            }
            if ($city_conditions) {
                $where[] = '(' . implode(' OR ', $city_conditions) . ')';
            }
        }

        // 是否購買
        $has_order_filter = !empty($filters['has_order']);
        if ($has_order_filter) {
            $join .= " INNER JOIN {$prefix}wc_orders o ON o.customer_id = m.id AND o.type = 'shop_order' AND o.status IN ('wc-completed','wc-processing')";
        }

        // 商品關鍵字（模糊比對商品名稱，含變化商品）
        $all_product_ids_sql = null; // 供後續明細查詢使用
        $filter_keywords = [];          // 供回傳前端產生圖表維度選項
        if (!empty($filters['product_keywords']) && is_array($filters['product_keywords'])) {
            $keywords        = array_slice($filters['product_keywords'], 0, 5); // 最多5組
            $filter_keywords = $keywords;
            // 先查出符合關鍵字的商品 ID（含父商品+變化商品）
            $like_conditions = [];
            foreach ($keywords as $kw) {
                $kw = trim($kw);
                if ($kw === '') continue;
                $like_conditions[] = $wpdb->prepare("p.post_title LIKE %s", '%' . $wpdb->esc_like($kw) . '%');
            }
            if ($like_conditions) {
                // 找出匹配的商品 ID（simple + variable parent）
                $product_sql = "SELECT p.ID FROM {$prefix}posts p WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish' AND (" . implode(' OR ', $like_conditions) . ")";
                // 也找出以匹配商品為父商品的變化商品
                $variation_sql = "SELECT v.ID FROM {$prefix}posts v WHERE v.post_type = 'product_variation' AND v.post_parent IN ({$product_sql})";
                $all_product_ids_sql = "({$product_sql}) UNION ({$variation_sql})";

                // 用 wc_order_product_lookup 高效查找包含這些商品的訂單客戶
                if (!$has_order_filter) {
                    $join .= " INNER JOIN {$prefix}wc_orders o_pk ON o_pk.customer_id = m.id AND o_pk.type = 'shop_order' AND o_pk.status = 'wc-completed'";
                    $pk_order_alias = "o_pk";
                } else {
                    $pk_order_alias = "o";
                }
                $join .= " INNER JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = {$pk_order_alias}.id AND opl.product_id IN ({$all_product_ids_sql})";
            }
        }

        // 得知管道、購買原因、課程用途 (存在訂單 post_meta)
        $meta_filters = [
            'source' => '_custom_source',
            'reason' => '_custom_reason',
            'usage'  => '_custom_usage',
        ];

        $meta_join_idx = 0;
        foreach ($meta_filters as $key => $meta_key) {
            if (!empty($filters[$key])) {
                $alias = "om{$meta_join_idx}";
                if (!$has_order_filter) {
                    $join .= " INNER JOIN {$prefix}wc_orders o_{$alias} ON o_{$alias}.customer_id = m.id AND o_{$alias}.type = 'shop_order'";
                    $order_alias = "o_{$alias}";
                } else {
                    $order_alias = "o";
                }
                $join .= $wpdb->prepare(
                    " INNER JOIN {$prefix}postmeta pm_{$alias} ON pm_{$alias}.post_id = {$order_alias}.id AND pm_{$alias}.meta_key = %s",
                    $meta_key
                );

                $meta_or = [];
                foreach ($filters[$key] as $val) {
                    $meta_or[] = $wpdb->prepare("pm_{$alias}.meta_value = %s", $val);
                }
                $where[] = '(' . implode(' OR ', $meta_or) . ')';
                $meta_join_idx++;
            }
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT DISTINCT m.id FROM {$prefix}mmr_users m {$join} {$where_sql}";

        $user_ids = $wpdb->get_col($sql);
        $db_error = $wpdb->last_error;

        // 取得使用者名稱及訂單數
        $users = [];
        if ($user_ids) {
            $id_placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            $users = $wpdb->get_results($wpdb->prepare(
                "SELECT u.ID, u.display_name, u.user_email,
                        (SELECT COUNT(*) FROM {$prefix}wc_orders wo WHERE wo.customer_id = u.ID AND wo.type = 'shop_order' AND wo.status IN ('wc-completed','wc-processing')) AS order_count
                 FROM {$prefix}users u WHERE u.ID IN ($id_placeholders)
                 HAVING order_count > 0",
                $user_ids
            ));
        }

        // 若有商品關鍵字，查詢各使用者購買的符合商品明細（合併變化商品至父商品名稱）
        if (!empty($all_product_ids_sql) && $user_ids) {
            $id_list = implode(',', array_map('intval', $user_ids));
            $breakdown_rows = $wpdb->get_results(
                "SELECT
                    o.customer_id,
                    COALESCE(pp.post_title, p.post_title) AS product_name,
                    SUM(opl.product_qty) AS qty
                 FROM {$prefix}wc_order_product_lookup opl
                 INNER JOIN {$prefix}wc_orders o ON o.id = opl.order_id AND o.type = 'shop_order' AND o.status IN ('wc-completed','wc-processing')
                 INNER JOIN {$prefix}posts p ON p.ID = opl.product_id
                 LEFT JOIN {$prefix}posts pp ON pp.ID = p.post_parent
                 WHERE o.customer_id IN ({$id_list})
                   AND opl.product_id IN ({$all_product_ids_sql})
                 GROUP BY o.customer_id, COALESCE(pp.post_title, p.post_title)
                 ORDER BY o.customer_id, qty DESC"
            );
            $breakdown_map = [];
            foreach ($breakdown_rows as $row) {
                $breakdown_map[$row->customer_id][] = [
                    'name' => $row->product_name,
                    'qty'  => intval($row->qty),
                ];
            }
            foreach ($users as &$user) {
                $user->product_breakdown = $breakdown_map[$user->ID] ?? [];
            }
            unset($user);
        }

        wp_send_json_success([
            'users'            => $users,
            'count'            => count($user_ids),
            'has_keywords'     => !empty($all_product_ids_sql),
            'product_keywords' => $filter_keywords,
            'debug_sql'        => $sql,
            'db_error'         => $db_error,
        ]);
    }

    /**
     * 取得使用者訂單
     */
    public static function sda_get_user_orders() {
        self::verify();
        global $wpdb;
        $prefix = $wpdb->prefix;

        $user_id = intval($_POST['user_id'] ?? 0);
        if (!$user_id) wp_send_json_error('Invalid user ID');

        // 商品關鍵字（用於 highlight）
        $keywords = json_decode(stripslashes($_POST['product_keywords'] ?? '[]'), true);
        $matched_order_ids = [];
        if (is_array($keywords) && count($keywords)) {
            $keywords = array_slice(array_filter(array_map('trim', $keywords)), 0, 5);
            if ($keywords) {
                $like_conditions = [];
                foreach ($keywords as $kw) {
                    $like_conditions[] = $wpdb->prepare("p.post_title LIKE %s", '%' . $wpdb->esc_like($kw) . '%');
                }
                $product_sql = "SELECT p.ID FROM {$prefix}posts p WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish' AND (" . implode(' OR ', $like_conditions) . ")";
                $variation_sql = "SELECT v.ID FROM {$prefix}posts v WHERE v.post_type = 'product_variation' AND v.post_parent IN ({$product_sql})";
                $all_product_ids_sql = "({$product_sql}) UNION ({$variation_sql})";

                $matched_order_ids = $wpdb->get_col(
                    "SELECT DISTINCT opl.order_id FROM {$prefix}wc_order_product_lookup opl
                     INNER JOIN {$prefix}wc_orders o ON o.id = opl.order_id AND o.customer_id = {$user_id} AND o.type = 'shop_order'
                     WHERE opl.product_id IN ({$all_product_ids_sql})"
                );
                $matched_order_ids = array_map('intval', $matched_order_ids);
            }
        }

        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT id, date_created_gmt, total_amount, status
             FROM {$prefix}wc_orders
             WHERE customer_id = %d AND type = 'shop_order' AND status IN ('wc-completed','wc-processing')
             ORDER BY date_created_gmt DESC",
            $user_id
        ));

        $result = [];
        foreach ($orders as $order) {
            $status_labels = [
                'wc-completed'  => '已完成',
                'wc-processing' => '處理中',
                'wc-on-hold'    => '保留',
                'wc-pending'    => '待付款',
                'wc-cancelled'  => '已取消',
                'wc-refunded'   => '已退款',
                'wc-failed'     => '失敗',
            ];
            $result[] = [
                'id'      => $order->id,
                'date'    => $order->date_created_gmt ? date('Y-m-d', strtotime($order->date_created_gmt)) : '',
                'total'   => number_format((float)$order->total_amount, 0),
                'status'  => $status_labels[$order->status] ?? $order->status,
                'url'     => admin_url('post.php?post=' . $order->id . '&action=edit'),
                'matched' => in_array((int)$order->id, $matched_order_ids, true),
            ];
        }

        wp_send_json_success($result);
    }

    /**
     * 取得圓餅圖資料
     */
    public static function sda_get_chart_data() {
        self::verify();
        global $wpdb;
        $prefix = $wpdb->prefix;

        $dimension = sanitize_text_field($_POST['dimension'] ?? '');
        $user_ids  = json_decode(stripslashes($_POST['user_ids'] ?? '[]'), true);

        if (!is_array($user_ids) || empty($user_ids)) {
            wp_send_json_error('No users');
        }

        $user_ids = array_map('intval', $user_ids);
        $id_placeholders = implode(',', array_fill(0, count($user_ids), '%d'));

        $labels = [];
        $values = [];

        switch ($dimension) {
            case 'gender':
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT gender AS label, COUNT(*) AS cnt FROM {$prefix}mmr_users WHERE id IN ($id_placeholders) GROUP BY gender",
                    $user_ids
                ));
                foreach ($rows as $r) {
                    $labels[] = $r->label ?: '未填';
                    $values[] = (int)$r->cnt;
                }
                break;

            case 'age':
                $age_ranges = [
                    '18歲以下' => [0, 17],
                    '18-22歲'  => [18, 22],
                    '23-25歲'  => [23, 25],
                    '26-29歲'  => [26, 29],
                    '30-35歲'  => [30, 35],
                    '36-45歲'  => [36, 45],
                    '46-55歲'  => [46, 55],
                    '56歲以上' => [56, 999],
                ];
                foreach ($age_ranges as $lbl => [$min, $max]) {
                    $cnt = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$prefix}mmr_users WHERE id IN ($id_placeholders) AND age >= %d AND age <= %d",
                        array_merge($user_ids, [$min, $max])
                    ));
                    if ($cnt > 0) {
                        $labels[] = $lbl;
                        $values[] = (int)$cnt;
                    }
                }
                break;

            case 'job':
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT j.class_text AS label, COUNT(*) AS cnt
                     FROM {$prefix}mmr_users m
                     LEFT JOIN {$prefix}job_class_2 j ON j.id = m.job_2
                     WHERE m.id IN ($id_placeholders)
                     GROUP BY m.job_2",
                    $user_ids
                ));
                foreach ($rows as $r) {
                    $labels[] = $r->label ?: '未填';
                    $values[] = (int)$r->cnt;
                }
                break;

            case 'test':
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT t.class_text AS label, COUNT(*) AS cnt
                     FROM {$prefix}mmr_users m
                     LEFT JOIN {$prefix}test_class_3 t ON t.id = m.test_3
                     WHERE m.id IN ($id_placeholders)
                     GROUP BY m.test_3",
                    $user_ids
                ));
                foreach ($rows as $r) {
                    $labels[] = $r->label ?: '未填';
                    $values[] = (int)$r->cnt;
                }
                break;

            case 'city':
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT contAddrCity AS label, COUNT(*) AS cnt
                     FROM {$prefix}mmr_users
                     WHERE id IN ($id_placeholders)
                     GROUP BY contAddrCity",
                    $user_ids
                ));
                foreach ($rows as $r) {
                    $labels[] = $r->label ?: '未填';
                    $values[] = (int)$r->cnt;
                }
                break;

            case 'has_order':
                $with_orders = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT m.id) FROM {$prefix}mmr_users m
                     INNER JOIN {$prefix}wc_orders o ON o.customer_id = m.id AND o.type = 'shop_order' AND o.status IN ('wc-completed','wc-processing')
                     WHERE m.id IN ($id_placeholders)",
                    $user_ids
                ));
                $total = count($user_ids);
                $without = $total - (int)$with_orders;
                $labels = ['有購買', '無購買'];
                $values = [(int)$with_orders, $without];
                break;

            case 'source':
            case 'reason':
            case 'usage':
                $meta_key_map = [
                    'source' => '_custom_source',
                    'reason' => '_custom_reason',
                    'usage'  => '_custom_usage',
                ];
                $meta_key = $meta_key_map[$dimension];
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT pm.meta_value AS label, COUNT(DISTINCT m.id) AS cnt
                     FROM {$prefix}mmr_users m
                     INNER JOIN {$prefix}wc_orders o ON o.customer_id = m.id AND o.type = 'shop_order'
                     INNER JOIN {$prefix}postmeta pm ON pm.post_id = o.id AND pm.meta_key = %s
                     WHERE m.id IN ($id_placeholders)
                     GROUP BY pm.meta_value",
                    array_merge([$meta_key], $user_ids)
                ));
                foreach ($rows as $r) {
                    $labels[] = $r->label ?: '未填';
                    $values[] = (int)$r->cnt;
                }
                break;

            default:
                if (strpos($dimension, 'product_keyword:') === 0) {
                    $kw  = sanitize_text_field(substr($dimension, strlen('product_keyword:')));
                    $like = '%' . $wpdb->esc_like($kw) . '%';
                    $product_ids_sql = $wpdb->prepare(
                        "SELECT p.ID FROM {$prefix}posts p WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish' AND p.post_title LIKE %s",
                        $like
                    );
                    $variation_sql = "SELECT v.ID FROM {$prefix}posts v WHERE v.post_type = 'product_variation' AND v.post_parent IN ({$product_ids_sql})";
                    $all_ids_sql   = "({$product_ids_sql}) UNION ({$variation_sql})";
                    $id_list       = implode(',', $user_ids);
                    $rows = $wpdb->get_results(
                        "SELECT COALESCE(pp.post_title, p.post_title) AS label, SUM(opl.product_qty) AS cnt
                         FROM {$prefix}wc_order_product_lookup opl
                         INNER JOIN {$prefix}wc_orders o ON o.id = opl.order_id AND o.type = 'shop_order' AND o.status IN ('wc-completed','wc-processing')
                         INNER JOIN {$prefix}posts p ON p.ID = opl.product_id
                         LEFT JOIN {$prefix}posts pp ON pp.ID = p.post_parent AND pp.post_type = 'product'
                         WHERE o.customer_id IN ({$id_list})
                           AND opl.product_id IN ({$all_ids_sql})
                         GROUP BY COALESCE(pp.ID, p.ID), COALESCE(pp.post_title, p.post_title)
                         ORDER BY cnt DESC"
                    );
                    foreach ($rows as $r) {
                        $labels[] = $r->label;
                        $values[] = (int)$r->cnt;
                    }
                } else {
                    wp_send_json_error('Invalid dimension');
                }
        }

        wp_send_json_success([
            'labels' => $labels,
            'values' => $values,
        ]);
    }
}
