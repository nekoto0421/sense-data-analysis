(function($) {
    'use strict';

    // 儲存的篩選群組 (最多5組)
    let filterGroups = [];
    let currentEditIndex = -1; // -1=新增, >=0=編輯
    let currentGroupIndex = -1; // 目前查看的群組
    let filteredUserIds = [];
    let pieChart = null;

    // 台灣縣市資料
    let twCityData = {};

    // 快取的選項資料
    let cachedOptions = null;

    // 追蹤已選項（切換下拉不遺失）
    let selectedJobs = [];  // [{id, text, level}]  level: 1=僅第一層, 2=第二層
    let selectedTests = []; // [{id, text, level}]  level: 1/2/3
    let selectedCities = []; // [{city, area, text}]

    $(document).ready(function() {
        loadInitialData();
        bindEvents();
    });

    function loadInitialData() {
        // 載入台灣縣市
        $.getJSON(sdaAjax.ajaxurl.replace('admin-ajax.php', '') + '../wp-content/plugins/sense-data-analysis/assets/data/tw-cities.json', function(data) {
            twCityData = data;
            populateCitySelect();
        });

        // 載入篩選選項
        $.post(sdaAjax.ajaxurl, {
            action: 'sda_get_filter_options',
            nonce: sdaAjax.nonce
        }, function(res) {
            if (res.success) {
                cachedOptions = res.data;
                populateFilterOptions(res.data);
            }
        });

        // 載入已儲存的篩選群組
        $.post(sdaAjax.ajaxurl, {
            action: 'sda_load_groups',
            nonce: sdaAjax.nonce
        }, function(res) {
            if (res.success && Array.isArray(res.data)) {
                filterGroups = res.data;
                renderGroupList();
            }
        });
    }

    function persistGroups() {
        $.post(sdaAjax.ajaxurl, {
            action: 'sda_save_groups',
            nonce: sdaAjax.nonce,
            groups: JSON.stringify(filterGroups)
        });
    }

    function populateCitySelect() {
        var $sel = $('.sda-city-select');
        $sel.empty().append('<option value="">請選擇縣市</option>');
        Object.keys(twCityData).forEach(function(city) {
            $sel.append('<option value="' + escHtml(city) + '">' + escHtml(city) + '</option>');
        });
    }

    function populateFilterOptions(data) {
        // 職業第一層
        var $job1 = $('select[data-type="job_class_1"]');
        $job1.empty().append('<option value="">請選擇職業類別</option>');
        data.job_class_1.forEach(function(item) {
            $job1.append('<option value="' + item.id + '">' + escHtml(item.class_text) + '</option>');
        });

        // 報考類科第一層
        var $test1 = $('select[data-type="test_class_1"]');
        $test1.empty().append('<option value="">請選擇考試</option>');
        data.test_class_1.forEach(function(item) {
            $test1.append('<option value="' + item.id + '">' + escHtml(item.class_text) + '</option>');
        });

        // 得知管道
        renderCheckboxOptions('#sda-source-options', data.source_options, 'source');
        // 購買原因
        renderCheckboxOptions('#sda-reason-options', data.reason_options, 'reason');
        // 課程用途
        renderCheckboxOptions('#sda-usage-options', data.usage_options, 'usage');
    }

    function renderCheckboxOptions(selector, options, filterName) {
        var $el = $(selector);
        $el.empty();
        if (Array.isArray(options)) {
            options.forEach(function(opt) {
                $el.append('<label><input type="checkbox" value="' + escHtml(opt) + '"> ' + escHtml(opt) + '</label>');
            });
        }
    }

    function bindEvents() {
        // 新增群組
        $('#sda-add-group').on('click', function() {
            if (filterGroups.length >= 5) {
                alert('最多只能設定5組篩選條件');
                return;
            }
            currentEditIndex = -1;
            resetModal();
            $('#sda-modal-title').text('新增篩選群組');
            $('#sda-modal-overlay').show();
        });

        // Modal 關閉
        $('.sda-modal-close, #sda-modal-cancel').on('click', function() {
            $('#sda-modal-overlay').hide();
        });

        // 儲存群組
        $('#sda-modal-save').on('click', saveGroup);

        // 職業第一層變更 -> 載入第二層（若無第二層則第一層可直接勾選）
        $(document).on('change', 'select[data-type="job_class_1"]', function() {
            var parentId = $(this).val();
            var selText = $(this).find('option:selected').text();
            var $container = $(this).closest('.sda-cascading-select').find('.sda-level-2-checkboxes[data-type="job_class_2"]');
            $container.empty();
            if (!parentId) return;
            loadSubOptions('job_class_2', parentId, function(items) {
                if (items.length > 0) {
                    if (items.length > 1) {
                        $container.append('<label class="sda-select-all"><input type="checkbox" class="sda-check-all"> <strong>全選</strong></label>');
                    }
                    items.forEach(function(item) {
                        var checked = selectedJobs.some(function(s) { return String(s.id) === String(item.id) && s.level === 2; });
                        $container.append('<label><input type="checkbox" value="' + item.id + '" data-text="' + escHtml(item.class_text) + '" data-level="2"' + (checked ? ' checked' : '') + '> ' + escHtml(item.class_text) + '</label>');
                    });
                } else {
                    var checked = selectedJobs.some(function(s) { return String(s.id) === String(parentId) && s.level === 1; });
                    $container.append('<label><input type="checkbox" value="' + parentId + '" data-text="' + escHtml(selText) + '" data-level="1"' + (checked ? ' checked' : '') + '> ' + escHtml(selText) + '</label>');
                }
                syncSelectAll($container);
            });
        });

        // 職業勾選 -> 同步 selectedJobs
        $(document).on('change', '.sda-level-2-checkboxes[data-type="job_class_2"] input:not(.sda-check-all)', function() {
            var id = $(this).val();
            var text = $(this).data('text');
            var level = parseInt($(this).data('level')) || 2;
            if ($(this).is(':checked')) {
                if (!selectedJobs.some(function(s) { return String(s.id) === String(id) && s.level === level; })) {
                    selectedJobs.push({id: id, text: text, level: level});
                }
            } else {
                selectedJobs = selectedJobs.filter(function(s) { return !(String(s.id) === String(id) && s.level === level); });
            }
            syncSelectAll($(this).closest('.sda-level-2-checkboxes'));
            updateTags('job');
        });

        // 職業全選
        $(document).on('change', '.sda-level-2-checkboxes[data-type="job_class_2"] .sda-check-all', function() {
            var checked = $(this).is(':checked');
            $(this).closest('.sda-level-2-checkboxes').find('input:not(.sda-check-all)').each(function() {
                $(this).prop('checked', checked);
                var id = $(this).val();
                var text = $(this).data('text');
                var level = parseInt($(this).data('level')) || 2;
                if (checked) {
                    if (!selectedJobs.some(function(s) { return String(s.id) === String(id) && s.level === level; })) {
                        selectedJobs.push({id: id, text: text, level: level});
                    }
                } else {
                    selectedJobs = selectedJobs.filter(function(s) { return !(String(s.id) === String(id) && s.level === level); });
                }
            });
            updateTags('job');
        });

        // 報考類科第一層 -> 第二層（若無第二層則第一層可直接勾選）
        $(document).on('change', 'select[data-type="test_class_1"]', function() {
            var parentId = $(this).val();
            var selText = $(this).find('option:selected').text();
            var $sel2 = $(this).closest('.sda-cascading-select').find('select[data-type="test_class_2"]');
            var $container = $(this).closest('.sda-cascading-select').find('.sda-level-3-checkboxes[data-type="test_class_3"]');
            $sel2.empty().append('<option value="">請選擇類別</option>');
            $container.empty();
            if (!parentId) return;
            loadSubOptions('test_class_2', parentId, function(items) {
                if (items.length > 0) {
                    items.forEach(function(item) {
                        $sel2.append('<option value="' + item.id + '">' + escHtml(item.class_text) + '</option>');
                    });
                } else {
                    // 無第二層，第一層本身可勾選
                    var checked = selectedTests.some(function(s) { return String(s.id) === String(parentId) && s.level === 1; });
                    $container.append('<label><input type="checkbox" value="' + parentId + '" data-text="' + escHtml(selText) + '" data-level="1"' + (checked ? ' checked' : '') + '> ' + escHtml(selText) + '</label>');
                }
            });
        });

        // 報考類科第二層 -> 第三層（若無第三層則第二層可直接勾選）
        $(document).on('change', 'select[data-type="test_class_2"]', function() {
            var parentId = $(this).val();
            var selText = $(this).find('option:selected').text();
            var $container = $(this).closest('.sda-cascading-select').find('.sda-level-3-checkboxes[data-type="test_class_3"]');
            $container.empty();
            if (!parentId) return;
            loadSubOptions('test_class_3', parentId, function(items) {
                if (items.length > 1) {
                    $container.append('<label class="sda-select-all"><input type="checkbox" class="sda-check-all"> <strong>全選</strong></label>');
                }
                if (items.length > 0) {
                    items.forEach(function(item) {
                        var checked = selectedTests.some(function(s) { return String(s.id) === String(item.id) && s.level === 3; });
                        $container.append('<label><input type="checkbox" value="' + item.id + '" data-text="' + escHtml(item.class_text) + '" data-level="3"' + (checked ? ' checked' : '') + '> ' + escHtml(item.class_text) + '</label>');
                    });
                } else {
                    var checked = selectedTests.some(function(s) { return String(s.id) === String(parentId) && s.level === 2; });
                    $container.append('<label><input type="checkbox" value="' + parentId + '" data-text="' + escHtml(selText) + '" data-level="2"' + (checked ? ' checked' : '') + '> ' + escHtml(selText) + '</label>');
                }
                syncSelectAll($container);
            });
        });

        // 報考類科勾選 -> 同步 selectedTests
        $(document).on('change', '.sda-level-3-checkboxes[data-type="test_class_3"] input:not(.sda-check-all)', function() {
            var id = $(this).val();
            var text = $(this).data('text');
            var level = $(this).data('level') === 2 ? 2 : 3;
            if ($(this).is(':checked')) {
                if (!selectedTests.some(function(s) { return String(s.id) === String(id) && s.level === level; })) {
                    selectedTests.push({id: id, text: text, level: level});
                }
            } else {
                selectedTests = selectedTests.filter(function(s) { return !(String(s.id) === String(id) && s.level === level); });
            }
            syncSelectAll($(this).closest('.sda-level-3-checkboxes'));
            updateTags('test');
        });

        // 報考類科全選
        $(document).on('change', '.sda-level-3-checkboxes[data-type="test_class_3"] .sda-check-all', function() {
            var checked = $(this).is(':checked');
            $(this).closest('.sda-level-3-checkboxes').find('input:not(.sda-check-all)').each(function() {
                $(this).prop('checked', checked);
                var id = $(this).val();
                var text = $(this).data('text');
                var level = $(this).data('level') === 2 ? 2 : 3;
                if (checked) {
                    if (!selectedTests.some(function(s) { return String(s.id) === String(id) && s.level === level; })) {
                        selectedTests.push({id: id, text: text, level: level});
                    }
                } else {
                    selectedTests = selectedTests.filter(function(s) { return !(String(s.id) === String(id) && s.level === level); });
                }
            });
            updateTags('test');
        });

        // 縣市選擇 -> 載入區域
        $(document).on('change', '.sda-city-select', function() {
            var city = $(this).val();
            var $container = $(this).closest('.sda-cascading-select').find('.sda-area-checkboxes');
            $container.empty();
            if (!city || !twCityData[city]) return;
            if (twCityData[city].length > 1) {
                $container.append('<label class="sda-select-all"><input type="checkbox" class="sda-check-all"> <strong>全選</strong></label>');
            }
            twCityData[city].forEach(function(area) {
                var checked = selectedCities.some(function(s) { return s.city === city && s.area === area; });
                $container.append('<label><input type="checkbox" value="' + escHtml(area) + '" data-city="' + escHtml(city) + '"' + (checked ? ' checked' : '') + '> ' + escHtml(area) + '</label>');
            });
            syncSelectAll($container);
        });

        // 區域勾選 -> 更新追蹤陣列與標籤
        $(document).on('change', '.sda-area-checkboxes input:not(.sda-check-all)', function() {
            var area = $(this).val();
            var city = $(this).data('city');
            var checked = $(this).is(':checked');
            if (checked) {
                if (!selectedCities.some(function(s) { return s.city === city && s.area === area; })) {
                    selectedCities.push({city: city, area: area, text: city + ' ' + area});
                }
            } else {
                selectedCities = selectedCities.filter(function(s) { return !(s.city === city && s.area === area); });
            }
            syncSelectAll($(this).closest('.sda-area-checkboxes'));
            updateTags('city');
        });

        // 區域全選
        $(document).on('change', '.sda-area-checkboxes .sda-check-all', function() {
            var isChecked = $(this).is(':checked');
            var $container = $(this).closest('.sda-area-checkboxes');
            $container.find('input:not(.sda-check-all)').each(function() {
                var wasChecked = $(this).is(':checked');
                if (wasChecked !== isChecked) {
                    $(this).prop('checked', isChecked).trigger('change');
                }
            });
        });

        // 商品關鍵字 - 新增
        $(document).on('click', '.sda-add-keyword', function() {
            var $container = $('#sda-product-keywords');
            if ($container.find('.sda-keyword-row').length >= 5) {
                alert('最多只能新增 5 組關鍵字');
                return;
            }
            $container.append('<div class="sda-keyword-row"><input type="text" class="sda-product-keyword" placeholder="輸入商品關鍵字"> <a href="#" class="sda-keyword-remove">×</a></div>');
        });

        // 商品關鍵字 - 移除
        $(document).on('click', '.sda-keyword-remove', function(e) {
            e.preventDefault();
            $(this).closest('.sda-keyword-row').remove();
        });

        // 圖表維度/類型選擇
        $('#sda-chart-dimension').on('change', function() {
            var dim = $(this).val();
            if (!dim || !filteredUserIds.length) {
                $('#sda-chart-container').hide();
                return;
            }
            loadChartData(dim);
        });

        $('#sda-chart-type').on('change', function() {
            var dim = $('#sda-chart-dimension').val();
            if (!dim || !filteredUserIds.length) return;
            loadChartData(dim);
        });

        // 使用者名稱點擊 -> 顯示訂單
        $(document).on('click', '.sda-user-link', function(e) {
            e.preventDefault();
            var userId = $(this).data('user-id');
            var userName = $(this).text();
            loadUserOrders(userId, userName);
        });

        // 返回使用者清單
        $('#sda-back-to-users').on('click', function(e) {
            e.preventDefault();
            $('#sda-order-list-container').hide();
            $('#sda-user-list-container').show();
        });
    }

    function syncSelectAll($container) {
        var $all = $container.find('.sda-check-all');
        if (!$all.length) return;
        var total = $container.find('input:not(.sda-check-all)').length;
        var checked = $container.find('input:not(.sda-check-all):checked').length;
        $all.prop('checked', total > 0 && total === checked);
    }

    function loadSubOptions(type, parentId, callback) {
        $.post(sdaAjax.ajaxurl, {
            action: 'sda_get_sub_options',
            nonce: sdaAjax.nonce,
            type: type,
            parent_id: parentId
        }, function(res) {
            if (res.success) callback(res.data);
        });
    }

    function updateTags(filterType) {
        var $tags = $('.sda-selected-tags[data-filter="' + filterType + '"]');
        var items = [];

        if (filterType === 'job') {
            items = selectedJobs.map(function(s) { return {id: s.id, text: s.text}; });
        } else if (filterType === 'test') {
            items = selectedTests.map(function(s) { return {id: s.id, text: s.text}; });
        } else if (filterType === 'city') {
            items = selectedCities.map(function(s) { return {city: s.city, area: s.area, text: s.text}; });
        }

        $tags.empty();
        items.forEach(function(item) {
            $tags.append('<span class="sda-tag">' + escHtml(item.text) + ' <a href="#" class="sda-tag-remove" data-id="' + (item.id || '') + '" data-city="' + (item.city || '') + '" data-area="' + (item.area || '') + '" data-filter="' + filterType + '">&times;</a></span>');
        });
    }

    // 標籤移除
    $(document).on('click', '.sda-tag-remove', function(e) {
        e.preventDefault();
        var filterType = $(this).data('filter');
        var id = $(this).data('id');
        var city = $(this).data('city');
        var area = $(this).data('area');

        if (filterType === 'city') {
            selectedCities = selectedCities.filter(function(s) { return !(s.city === city && s.area === area); });
            $('.sda-area-checkboxes input[value="' + area + '"][data-city="' + city + '"]').prop('checked', false);
        } else if (filterType === 'job') {
            selectedJobs = selectedJobs.filter(function(s) { return String(s.id) !== String(id); });
            $('.sda-level-2-checkboxes[data-type="job_class_2"] input[value="' + id + '"]').prop('checked', false);
            syncSelectAll($('.sda-level-2-checkboxes[data-type="job_class_2"]'));
        } else if (filterType === 'test') {
            selectedTests = selectedTests.filter(function(s) { return String(s.id) !== String(id); });
            $('.sda-level-3-checkboxes[data-type="test_class_3"] input[value="' + id + '"]').prop('checked', false);
            syncSelectAll($('.sda-level-3-checkboxes[data-type="test_class_3"]'));
        }
        updateTags(filterType);
    });

    function resetModal() {
        $('#sda-group-name').val('');
        $('#sda-modal .sda-checkbox-group input, #sda-modal .sda-level-2-checkboxes input, #sda-modal .sda-level-3-checkboxes input, .sda-area-checkboxes input').prop('checked', false);
        $('select[data-type="job_class_1"], select[data-type="test_class_1"], select[data-type="test_class_2"]').val('');
        $('.sda-city-select').val('');
        $('.sda-level-2-checkboxes, .sda-level-3-checkboxes, .sda-area-checkboxes').empty();
        $('.sda-selected-tags').empty();
        selectedJobs = [];
        selectedTests = [];
        selectedCities = [];
        // 清空商品關鍵字
        $('#sda-product-keywords').html('<div class="sda-keyword-row"><input type="text" class="sda-product-keyword" placeholder="輸入商品關鍵字"></div>');
    }

    function collectFilters() {
        var filters = {};

        // 性別
        var gender = [];
        $('[data-filter="gender"] input:checked').each(function() { gender.push($(this).val()); });
        if (gender.length) filters.gender = gender;

        // 年齡
        var age = [];
        $('[data-filter="age"] input:checked').each(function() { age.push($(this).val()); });
        if (age.length) filters.age = age;

        // 職業（從追蹤陣列取得，區分第一層與第二層）
        var job = [], job_1 = [];
        selectedJobs.forEach(function(s) {
            if (s.level === 1) { job_1.push(s.id); } else { job.push(s.id); }
        });
        if (job.length) filters.job = job;
        if (job_1.length) filters.job_1 = job_1;
        if (selectedJobs.length) {
            filters.job_labels = {};
            selectedJobs.forEach(function(s) { filters.job_labels[s.id] = s.text; });
        }

        // 報考類科（從追蹤陣列取得，區分第一、二、三層）
        var test = [], test_2 = [], test_1 = [];
        selectedTests.forEach(function(s) {
            if (s.level === 1) { test_1.push(s.id); }
            else if (s.level === 2) { test_2.push(s.id); }
            else { test.push(s.id); }
        });
        if (test.length) filters.test = test;
        if (test_2.length) filters.test_2 = test_2;
        if (test_1.length) filters.test_1 = test_1;
        if (selectedTests.length) {
            filters.test_labels = {};
            selectedTests.forEach(function(s) { filters.test_labels[s.id] = s.text; });
        }

        // 縣市（從追蹤陣列取得）
        if (selectedCities.length) {
            filters.city = selectedCities.map(function(s) { return {city: s.city, area: s.area}; });
        }

        // 得知管道
        var source = [];
        $('[data-filter="source"] input:checked').each(function() { source.push($(this).val()); });
        if (source.length) filters.source = source;

        // 購買原因
        var reason = [];
        $('[data-filter="reason"] input:checked').each(function() { reason.push($(this).val()); });
        if (reason.length) filters.reason = reason;

        // 課程用途
        var usage = [];
        $('[data-filter="usage"] input:checked').each(function() { usage.push($(this).val()); });
        if (usage.length) filters.usage = usage;

        // 是否購買
        if ($('[data-filter="has_order"] input:checked').length) {
            filters.has_order = true;
        }

        // 商品關鍵字
        var keywords = [];
        $('.sda-product-keyword').each(function() {
            var v = $.trim($(this).val());
            if (v) keywords.push(v);
        });
        if (keywords.length) filters.product_keywords = keywords;

        return filters;
    }

    function saveGroup() {
        var name = $.trim($('#sda-group-name').val());
        if (!name) {
            alert('請輸入群組名稱');
            return;
        }

        var filters = collectFilters();
        var group = { name: name, filters: filters };

        if (currentEditIndex >= 0) {
            filterGroups[currentEditIndex] = group;
        } else {
            filterGroups.push(group);
        }

        $('#sda-modal-overlay').hide();
        renderGroupList();
        persistGroups();
    }

    function renderGroupList() {
        var $list = $('#sda-groups-list');
        $list.empty();
        $('#sda-group-count').text('(' + filterGroups.length + '/5)');

        filterGroups.forEach(function(group, idx) {
            var filterSummary = buildFilterSummary(group.filters);
            var $item = $('<div class="sda-group-item">' +
                '<div class="sda-group-info">' +
                    '<strong>' + escHtml(group.name) + '</strong>' +
                    '<div class="sda-group-summary">' + filterSummary + '</div>' +
                '</div>' +
                '<div class="sda-group-actions">' +
                    '<button class="button sda-group-view" data-index="' + idx + '">查看結果</button> ' +
                    '<button class="button sda-group-edit" data-index="' + idx + '">編輯</button> ' +
                    '<button class="button sda-group-delete" data-index="' + idx + '">刪除</button>' +
                '</div>' +
            '</div>');
            $list.append($item);
        });

        // 查看結果
        $('.sda-group-view').off('click').on('click', function() {
            var idx = $(this).data('index');
            viewGroupResults(idx);
        });

        // 編輯
        $('.sda-group-edit').off('click').on('click', function() {
            var idx = $(this).data('index');
            editGroup(idx);
        });

        // 刪除
        $('.sda-group-delete').off('click').on('click', function() {
            var idx = $(this).data('index');
            if (confirm('確定刪除此篩選群組？')) {
                filterGroups.splice(idx, 1);
                renderGroupList();
                persistGroups();
                if (currentGroupIndex === idx) {
                    $('#sda-results').hide();
                    currentGroupIndex = -1;
                }
            }
        });
    }

    function buildFilterSummary(filters) {
        var parts = [];
        if (filters.gender) parts.push('性別: ' + filters.gender.join(', '));
        if (filters.age) parts.push('年齡: ' + filters.age.length + '組');
        var jobCount = (filters.job ? filters.job.length : 0) + (filters.job_1 ? filters.job_1.length : 0);
        if (jobCount) parts.push('職業: ' + jobCount + '項');
        var testCount = (filters.test ? filters.test.length : 0) + (filters.test_2 ? filters.test_2.length : 0) + (filters.test_1 ? filters.test_1.length : 0);
        if (testCount) parts.push('類科: ' + testCount + '項');
        if (filters.city) parts.push('縣市: ' + filters.city.length + '區');
        if (filters.source) parts.push('管道: ' + filters.source.length + '項');
        if (filters.reason) parts.push('原因: ' + filters.reason.length + '項');
        if (filters.usage) parts.push('用途: ' + filters.usage.length + '項');
        if (filters.has_order) parts.push('僅有購買');
        if (filters.product_keywords) parts.push('商品: ' + filters.product_keywords.join(', '));
        return parts.length ? parts.join(' | ') : '無條件';
    }

    function editGroup(idx) {
        currentEditIndex = idx;
        resetModal();
        var group = filterGroups[idx];
        $('#sda-group-name').val(group.name);
        $('#sda-modal-title').text('編輯篩選群組');

        var f = group.filters;

        // 還原簡單的 checkbox
        if (f.gender) f.gender.forEach(function(v) {
            $('[data-filter="gender"] input[value="' + v + '"]').prop('checked', true);
        });
        if (f.age) f.age.forEach(function(v) {
            $('[data-filter="age"] input[value="' + v + '"]').prop('checked', true);
        });
        if (f.source) f.source.forEach(function(v) {
            $('[data-filter="source"] input[value="' + v + '"]').prop('checked', true);
        });
        if (f.reason) f.reason.forEach(function(v) {
            $('[data-filter="reason"] input[value="' + v + '"]').prop('checked', true);
        });
        if (f.usage) f.usage.forEach(function(v) {
            $('[data-filter="usage"] input[value="' + v + '"]').prop('checked', true);
        });
        if (f.has_order) {
            $('[data-filter="has_order"] input').prop('checked', true);
        }

        // 多層選項 - 從 labels 還原到追蹤陣列
        // 職業
        if (f.job && f.job.length) {
            f.job.forEach(function(id) {
                selectedJobs.push({id: id, text: (f.job_labels && f.job_labels[id]) || ('ID:' + id), level: 2});
            });
        }
        if (f.job_1 && f.job_1.length) {
            f.job_1.forEach(function(id) {
                selectedJobs.push({id: id, text: (f.job_labels && f.job_labels[id]) || ('ID:' + id), level: 1});
            });
        }
        if (selectedJobs.length) updateTags('job');

        // 報考類科
        selectedTests = [];
        if (f.test && f.test.length) {
            f.test.forEach(function(id) {
                selectedTests.push({id: id, text: (f.test_labels && f.test_labels[id]) || ('ID:' + id), level: 3});
            });
        }
        if (f.test_2 && f.test_2.length) {
            f.test_2.forEach(function(id) {
                selectedTests.push({id: id, text: (f.test_labels && f.test_labels[id]) || ('ID:' + id), level: 2});
            });
        }
        if (f.test_1 && f.test_1.length) {
            f.test_1.forEach(function(id) {
                selectedTests.push({id: id, text: (f.test_labels && f.test_labels[id]) || ('ID:' + id), level: 1});
            });
        }
        if (selectedTests.length) updateTags('test');

        // 縣市（還原到追蹤陣列）
        if (f.city && f.city.length) {
            f.city.forEach(function(item) {
                selectedCities.push({city: item.city, area: item.area, text: item.city + ' ' + item.area});
            });
            updateTags('city');
        }

        // 商品關鍵字
        if (f.product_keywords && f.product_keywords.length) {
            var $container = $('#sda-product-keywords');
            $container.empty();
            f.product_keywords.forEach(function(kw, i) {
                var removeBtn = i === 0 ? '' : ' <a href="#" class="sda-keyword-remove">×</a>';
                $container.append('<div class="sda-keyword-row"><input type="text" class="sda-product-keyword" placeholder="輸入商品關鍵字" value="' + escHtml(kw) + '">' + removeBtn + '</div>');
            });
        }

        $('#sda-modal-overlay').show();
    }

    function viewGroupResults(idx) {
        currentGroupIndex = idx;
        var group = filterGroups[idx];
        $('#sda-result-group-name').text(group.name);
        $('#sda-chart-dimension').val('');
        $('#sda-chart-container').hide();
        $('#sda-order-list-container').hide();
        $('#sda-user-list-container').show();
        $('#sda-results').show();

        $.post(sdaAjax.ajaxurl, {
            action: 'sda_filter_users',
            nonce: sdaAjax.nonce,
            filters: JSON.stringify(group.filters)
        }, function(res) {
            console.log('[SDA] filter response:', res);
            if (res.data && res.data.debug_sql) {
                console.log('[SDA] SQL:', res.data.debug_sql);
            }
            if (res.data && res.data.db_error) {
                console.log('[SDA] DB Error:', res.data.db_error);
            }
            if (res.success) {
                filteredUserIds = res.data.users.map(function(u) { return u.ID; });
                $('#sda-user-count').text(res.data.count);
                var $tbody = $('#sda-user-table tbody');
                $tbody.empty();
                if (res.data.users.length === 0) {
                    $tbody.append('<tr><td colspan="2">無符合條件的使用者</td></tr>');
                } else {
                    res.data.users.forEach(function(u) {
                        var label = u.user_email;
                        if (u.display_name && u.display_name !== u.user_email) {
                            label = u.user_email + '(' + u.display_name + ')';
                        }
                        $tbody.append('<tr><td><a href="#" class="sda-user-link" data-user-id="' + u.ID + '">' + escHtml(label) + '</a></td><td>' + (u.order_count || 0) + '</td></tr>');
                    });
                }
            }
        });
    }

    function loadUserOrders(userId, userName) {
        $('#sda-order-user-name').text(userName);
        $('#sda-user-list-container').hide();
        $('#sda-order-list-container').show();

        // 取得當前群組的商品關鍵字
        var keywords = [];
        if (currentGroupIndex >= 0 && filterGroups[currentGroupIndex] && filterGroups[currentGroupIndex].filters.product_keywords) {
            keywords = filterGroups[currentGroupIndex].filters.product_keywords;
        }

        $.post(sdaAjax.ajaxurl, {
            action: 'sda_get_user_orders',
            nonce: sdaAjax.nonce,
            user_id: userId,
            product_keywords: JSON.stringify(keywords)
        }, function(res) {
            var $tbody = $('#sda-order-table tbody');
            $tbody.empty();
            if (res.success && res.data.length) {
                res.data.forEach(function(order) {
                    var rowClass = order.matched ? ' class="sda-order-matched"' : '';
                    $tbody.append('<tr' + rowClass + '>' +
                        '<td>#' + order.id + '</td>' +
                        '<td>' + escHtml(order.date) + '</td>' +
                        '<td>$' + escHtml(order.total) + '</td>' +
                        '<td>' + escHtml(order.status) + '</td>' +
                        '<td><a href="' + escHtml(order.url) + '" target="_blank">查看</a></td>' +
                    '</tr>');
                });
            } else {
                $tbody.append('<tr><td colspan="5">無訂單記錄</td></tr>');
            }
        });
    }

    function loadChartData(dimension) {
        $.post(sdaAjax.ajaxurl, {
            action: 'sda_get_chart_data',
            nonce: sdaAjax.nonce,
            dimension: dimension,
            user_ids: JSON.stringify(filteredUserIds)
        }, function(res) {
            if (res.success) {
                renderChart(res.data.labels, res.data.values);
            }
        });
    }

    function renderChart(labels, values) {
        $('#sda-chart-container').show();
        var ctx = document.getElementById('sda-pie-chart').getContext('2d');
        var chartType = $('#sda-chart-type').val() || 'pie';

        if (pieChart) pieChart.destroy();

        var colors = [
            '#FF6384','#36A2EB','#FFCE56','#4BC0C0','#9966FF',
            '#FF9F40','#C9CBCF','#7BC8A4','#E7E9ED','#F7464A',
            '#46BFBD','#FDB45C','#949FB1','#4D5360','#AC64AD'
        ];

        var chartOptions = {
            responsive: true,
            plugins: {
                legend: { display: chartType === 'pie', position: 'right' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            var total = context.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                            var pct = ((context.parsed.y || context.parsed) / total * 100).toFixed(1);
                            var val = context.parsed.y !== undefined ? context.parsed.y : context.parsed;
                            return context.label + ': ' + val + ' (' + pct + '%)';
                        }
                    }
                }
            }
        };

        if (chartType === 'bar') {
            chartOptions.scales = {
                y: { beginAtZero: true, ticks: { precision: 0 } }
            };
        }

        pieChart = new Chart(ctx, {
            type: chartType,
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors.slice(0, labels.length),
                }]
            },
            options: chartOptions
        });
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

})(jQuery);
