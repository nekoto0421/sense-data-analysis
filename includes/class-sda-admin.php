<?php
if (!defined('ABSPATH')) exit;

class SDA_Admin {

    public static function add_menu() {
        add_menu_page(
            '資料分析系統',
            '資料分析',
            'manage_options',
            'sense-data-analysis',
            [__CLASS__, 'render_page'],
            'dashicons-chart-pie',
            30
        );
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_sense-data-analysis') return;

        wp_enqueue_style('sda-style', SDA_PLUGIN_URL . 'assets/css/sda-style.css', [], '1.0.0');
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true);
        wp_enqueue_script('chart-datalabels', 'https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js', ['chart-js'], '2.2.0', true);
        wp_enqueue_script('sda-script', SDA_PLUGIN_URL . 'assets/js/sda-script.js', ['jquery', 'chart-js', 'chart-datalabels'], '1.0.0', true);
        wp_localize_script('sda-script', 'sdaAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('sda_nonce'),
        ]);
    }

    public static function render_page() {
        ?>
        <div class="wrap" id="sda-app">
            <h1>資料分析系統</h1>

            <!-- 篩選條件管理區 -->
            <div id="sda-filter-manager">
                <h2>篩選條件群組 <span id="sda-group-count">(0/5)</span></h2>
                <button type="button" class="button button-primary" id="sda-add-group">＋ 新增篩選群組</button>

                <div id="sda-groups-list"></div>
            </div>

            <!-- 新增/編輯群組 Modal -->
            <div id="sda-modal-overlay" style="display:none;">
                <div id="sda-modal">
                    <div class="sda-modal-header">
                        <h3 id="sda-modal-title">新增篩選群組</h3>
                        <button type="button" class="sda-modal-close">&times;</button>
                    </div>
                    <div class="sda-modal-body">
                        <div class="sda-field-row">
                            <label>群組名稱</label>
                            <input type="text" id="sda-group-name" placeholder="輸入群組名稱">
                        </div>

                        <!-- 性別 -->
                        <div class="sda-field-row">
                            <label>性別</label>
                            <div class="sda-checkbox-group" data-filter="gender">
                                <label><input type="checkbox" value="先生"> 先生</label>
                                <label><input type="checkbox" value="小姐"> 小姐</label>
                            </div>
                        </div>

                        <!-- 年齡 -->
                        <div class="sda-field-row">
                            <label>年齡</label>
                            <div class="sda-checkbox-group" data-filter="age">
                                <label><input type="checkbox" value="0-17"> 18歲以下</label>
                                <label><input type="checkbox" value="18-22"> 18 ─ 22歲</label>
                                <label><input type="checkbox" value="23-25"> 23 ─ 25歲</label>
                                <label><input type="checkbox" value="26-29"> 26 ─ 29歲</label>
                                <label><input type="checkbox" value="30-35"> 30 ─ 35歲</label>
                                <label><input type="checkbox" value="36-45"> 36 ─ 45歲</label>
                                <label><input type="checkbox" value="46-55"> 46 ─ 55歲</label>
                                <label><input type="checkbox" value="56-999"> 56歲以上</label>
                            </div>
                        </div>

                        <!-- 職業/身分 -->
                        <div class="sda-field-row">
                            <label>職業 / 身分</label>
                            <div class="sda-cascading-select" data-filter="job">
                                <select class="sda-level-1" data-type="job_class_1">
                                    <option value="">請選擇職業類別</option>
                                </select>
                                <div class="sda-level-2-checkboxes" data-type="job_class_2"></div>
                            </div>
                            <div class="sda-selected-tags" data-filter="job"></div>
                        </div>

                        <!-- 報考類科 -->
                        <div class="sda-field-row">
                            <label>報考類科</label>
                            <div class="sda-cascading-select" data-filter="test">
                                <select class="sda-level-1" data-type="test_class_1">
                                    <option value="">請選擇考試</option>
                                </select>
                                <select class="sda-level-2" data-type="test_class_2">
                                    <option value="">請選擇類別</option>
                                </select>
                                <div class="sda-level-3-checkboxes" data-type="test_class_3"></div>
                            </div>
                            <div class="sda-selected-tags" data-filter="test"></div>
                        </div>

                        <!-- 縣市/區域 -->
                        <div class="sda-field-row">
                            <label>縣市 / 區域</label>
                            <div class="sda-cascading-select" data-filter="city">
                                <select class="sda-level-1 sda-city-select">
                                    <option value="">請選擇縣市</option>
                                </select>
                                <div class="sda-level-2-checkboxes sda-area-checkboxes"></div>
                            </div>
                            <div class="sda-selected-tags" data-filter="city"></div>
                        </div>

                        <!-- 得知管道 -->
                        <div class="sda-field-row">
                            <label>得知管道</label>
                            <div class="sda-checkbox-group" data-filter="source" id="sda-source-options"></div>
                        </div>

                        <!-- 購買原因 -->
                        <div class="sda-field-row">
                            <label>購買原因</label>
                            <div class="sda-checkbox-group" data-filter="reason" id="sda-reason-options"></div>
                        </div>

                        <!-- 課程用途 -->
                        <div class="sda-field-row">
                            <label>課程用途</label>
                            <div class="sda-checkbox-group" data-filter="usage" id="sda-usage-options"></div>
                        </div>

                        <!-- 商品關鍵字 -->
                        <div class="sda-field-row">
                            <label>商品關鍵字（模糊比對訂單商品名稱，最多5組）</label>
                            <div id="sda-product-keywords">
                                <div class="sda-keyword-row"><input type="text" class="sda-product-keyword" placeholder="輸入商品關鍵字"></div>
                            </div>
                            <button type="button" class="button sda-add-keyword">＋ 新增關鍵字</button>
                        </div>

                        <!-- 是否購買 -->
                        <div class="sda-field-row">
                            <label>是否購買</label>
                            <div class="sda-checkbox-group" data-filter="has_order">
                                <label><input type="checkbox" value="1"> 僅篩選有購買的使用者</label>
                            </div>
                        </div>
                    </div>
                    <div class="sda-modal-footer">
                        <button type="button" class="button" id="sda-modal-cancel">取消</button>
                        <button type="button" class="button button-primary" id="sda-modal-save">儲存</button>
                    </div>
                </div>
            </div>

            <!-- 結果區 -->
            <div id="sda-results" style="display:none;">
                <h2>篩選結果 - <span id="sda-result-group-name"></span></h2>

                <!-- 單選圖表條件 -->
                <div class="sda-chart-filter">
                    <label>圖表分析維度：</label>
                    <select id="sda-chart-dimension">
                        <option value="">請選擇</option>
                        <option value="gender">性別</option>
                        <option value="age">年齡</option>
                        <option value="test">報考類科</option>
                        <option value="job">職業</option>
                        <option value="city">縣市</option>
                        <option value="has_order">是否購買</option>
                        <option value="source">得知管道</option>
                        <option value="reason">購買原因</option>
                        <option value="usage">課程用途</option>
                    </select>
                    <label style="margin-left:16px;">圖表類型：</label>
                    <select id="sda-chart-type">
                        <option value="pie">圓餅圖</option>
                        <option value="bar">長條圖</option>
                    </select>
                </div>

                <div id="sda-chart-container" style="display:none;">
                    <canvas id="sda-pie-chart"></canvas>
                </div>

                <!-- 使用者清單 -->
                <div id="sda-user-list-container">
                    <h3>使用者清單 (<span id="sda-user-count">0</span>)</h3>
                    <table class="wp-list-table widefat fixed striped" id="sda-user-table">
                        <thead><tr><th>使用者名稱</th><th style="width:80px;">訂單數</th><th id="sda-product-col-th">購買商品</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>

                <!-- 訂單清單 -->
                <div id="sda-order-list-container" style="display:none;">
                    <h3><a href="#" id="sda-back-to-users">← 返回使用者清單</a> | <span id="sda-order-user-name"></span> 的訂單</h3>
                    <table class="wp-list-table widefat fixed striped" id="sda-order-table">
                        <thead><tr><th>訂單編號</th><th>日期</th><th>金額</th><th>狀態</th><th>操作</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}
