<?php

declare(strict_types=1);

namespace Tools\Report;

use RuntimeException;

final class FinalReportWriter
{
    public function write(string $reportPath, array $results): void
    {
        $directory = dirname($reportPath);

        if(!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)){
            throw new RuntimeException('报告目录创建失败：' . $directory);
        }

        $content = $this->buildReport($results);

        if(file_put_contents($reportPath, $content) === false){
            throw new RuntimeException('最终检查报告写入失败：' . $reportPath);
        }
    }

    private function buildReport(array $results): string
    {
        $lines = [];
        $meta = $results['meta'];
        $javascript = $results['syntax']['javascript'];
        $database = $results['database'];

        $lines[] = '# Easy EV Charging 最终检查报告';
        $lines[] = '';
        $lines[] = '> 报告生成时间：' . $meta['generated_at'] . '  ';
        $lines[] = '> PHP版本：`' . $meta['php_version'] . '`  ';
        $lines[] = '> Node.js版本：'
            . (
                $javascript['node_version'] !== null
                    ? '`'
                        . $this->escapeInlineCode($javascript['node_version'])
                        . '`'
                    : '未获取'
            )
            . '  ';
        $lines[] = '> PHP业务时区：`' . $meta['php_timezone'] . '`  ';
        $lines[] = '> 数据库版本：' . ($database['version'] ?? '未获取') . '  ';
        $lines[] = '> 检查耗时：' . $meta['elapsed_seconds'] . ' 秒';
        $lines[] = '';

        $this->appendConclusionSection($lines, $results);
        $this->appendSummarySection($lines, $results);
        $this->appendStructureSection($lines, $results);
        $this->appendSyntaxSection($lines, $results);
        $this->appendTestSection($lines, $results);
        $this->appendBusinessRuleSection($lines, $results);
        $this->appendDatabaseSection($lines, $results);
        $this->appendSecuritySection($lines, $results);
        $this->appendEvidenceSection($lines);
        $this->appendManualCheckSection($lines);
        $this->appendLimitationsSection($lines);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function appendConclusionSection(array &$lines, array $results): void
    {
        $overall = $results['overall'];
        $status = $overall['passed'] ? '✅ 通过' : '❌ 未通过';

        $lines[] = '## 1. 最终结论';
        $lines[] = '';
        $lines[] = '**' . $status . '**';
        $lines[] = '';

        if($overall['passed']){
            $lines[] = '当前版本已通过本报告定义范围内的PHP与JavaScript语法检查、自动化测试、数据库结构与时间检查、安全控制静态检查、关键业务规则保障检查和其他静态检查。';
            $lines[] = '';
            $lines[] = '本轮自动检查未发现阻断当前版本交付的明确问题。浏览器视觉、响应式渲染、完整导航体验等内容不参与自动判定，相关事项列于“需人工检查项目”。';
        }else{
            $lines[] = '当前版本存在未通过的自动检查项目，需要根据报告中的失败详情修复后重新生成报告。';
            $lines[] = '';
            $lines[] = '未通过项目：' . $this->inlineList($overall['failed_checks']) . '。';
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
    }

    private function appendSummarySection(array &$lines, array $results): void
    {
        $php = $results['syntax']['php'];
        $javascript = $results['syntax']['javascript'];
        $tests = $results['tests'];
        $database = $results['database'];
        $csrf = $results['security']['csrf'];
        $sql = $results['security']['sql'];
        $css = $results['quality']['css'];
        $controls = $results['security']['controls'];
        $rules = $results['business_rules'];

        $rows = [
            [
                'PHP语法检查',
                $this->status($php['passed']),
                $php['passed_count'] . ' / ' . $php['total'],
            ],
            [
                'JavaScript语法检查',
                $this->status($javascript['passed']),
                $this->javascriptSummary($javascript),
            ],
            [
                '自动化测试',
                $this->status($tests['passed']),
                $tests['passed_count'] . ' / ' . $tests['total'],
            ],
            [
                '必要文件检查',
                $this->status($results['required_files']['passed']),
                $results['required_files']['missing_count'] . ' 个缺失',
            ],
            [
                '临时开发标记',
                $this->status($results['markers']['passed']),
                $results['markers']['count'] . ' 项',
            ],
            [
                '数据库结构与时间',
                $this->status($database['passed']),
                $this->databaseSummary($database),
            ],
            [
                'POST入口CSRF覆盖',
                $this->status($csrf['passed']),
                $csrf['covered_count'] . ' / ' . $csrf['post_file_count'],
            ],
            [
                'SQL直接调用复查',
                $this->status($sql['passed']),
                $sql['query_count'] . ' 处直接 query()',
            ],
            [
                'CSS使用关系',
                $this->status($css['passed']),
                $css['unused_count'] . ' 项明确无使用候选',
            ],
            [
                '安全控制静态检查',
                $this->status($controls['passed']),
                $controls['passed_count'] . ' / ' . $controls['total'],
            ],
            [
                '关键业务规则保障',
                $this->status($rules['passed']),
                $rules['passed_count'] . ' / ' . $rules['total'],
            ],
        ];

        $lines[] = '## 2. 检查结果摘要';
        $lines[] = '';

        $this->appendTable(
            $lines,
            ['检查项目', '状态', '结果'],
            $rows
        );

        $lines[] = '';
    }

    private function appendStructureSection(array &$lines, array $results): void
    {
        $structure = $results['structure'];

        $rows = [
            ['PHP文件', (string)$structure['php_files']],
            ['应用层类文件', (string)$structure['app_class_files']],
            ['Public PHP页面', (string)$structure['public_php_pages']],
            ['视图文件', (string)$structure['view_files']],
            ['CSS文件', (string)$structure['css_files']],
            ['JavaScript文件', (string)$structure['javascript_files']],
            ['SQL文件', (string)$structure['sql_files']],
            ['CLI工具', (string)$structure['cli_tools']],
            ['测试文件', (string)$structure['test_files']],
        ];

        $lines[] = '## 3. 项目结构概览';
        $lines[] = '';

        $this->appendTable(
            $lines,
            ['类型', '数量'],
            $rows
        );

        $lines[] = '';
        $lines[] = '项目采用 `Controllers + Core + Models + Repositories + Services` 的应用层结构。页面入口位于 `public/`，公共视图位于 `views/`，数据库脚本位于 `database/`，自动化测试分为 Unit Test 与 Feature Test。';
        $lines[] = '';
    }

    private function appendSyntaxSection(array &$lines, array $results): void
    {
        $javascript = $results['syntax']['javascript'];
        $css = $results['quality']['css'];
        $php = $results['syntax']['php'];
        $markers = $results['markers'];
        $requiredFiles = $results['required_files'];

        $lines[] = '## 4. 语法与静态检查';
        $lines[] = '';
        $lines[] = '### 4.1 PHP语法';
        $lines[] = '';
        $lines[] = '- 检查文件：**' . $php['total'] . '**';
        $lines[] = '- 通过：**' . $php['passed_count'] . '**';
        $lines[] = '- 失败：**' . $php['failed_count'] . '**';
        $lines[] = '';

        if($php['failures'] !== []){
            $rows = [];

            foreach($php['failures'] as $failure){
                $rows[] = [
                    $failure['file'],
                    $failure['message'],
                ];
            }

            $this->appendTable(
                $lines,
                ['文件', '错误信息'],
                $rows
            );

            $lines[] = '';
        }

        $lines[] = '### 4.2 JavaScript语法';
        $lines[] = '';

        if(!$javascript['available']){
            $lines[] = '- 状态：**❌ 未通过**';
            $lines[] = '- 原因：' . $javascript['message'];
        }else{
            $lines[] = '- Node.js版本：`'
                . $this->escapeInlineCode($javascript['node_version'])
                . '`';
            $lines[] = '- 检查文件：**' . $javascript['total'] . '**';
            $lines[] = '- 通过：**' . $javascript['passed_count'] . '**';
            $lines[] = '- 失败：**' . $javascript['failed_count'] . '**';
        }

        $lines[] = '';

        if($javascript['failures'] !== []){
            $rows = [];

            foreach($javascript['failures'] as $failure){
                $rows[] = [
                    $failure['file'],
                    $failure['message'],
                ];
            }

            $this->appendTable(
                $lines,
                ['文件', '错误信息'],
                $rows
            );

            $lines[] = '';
        }

        $lines[] = '### 4.3 临时标记与必要文件';
        $lines[] = '';
        $lines[] = '- `'
            . $this->developmentMarkerLabel()
            . '`：**'
            . $markers['count']
            . ' 项**';
        $lines[] = '- 必要文件：**'
            . ($requiredFiles['passed'] ? '全部存在' : '存在缺失')
            . '**';
        $lines[] = '';

        if($markers['items'] !== []){
            $rows = [];

            foreach($markers['items'] as $item){
                $rows[] = [
                    $item['file'],
                    (string)$item['line'],
                    $item['marker'],
                ];
            }

            $this->appendTable(
                $lines,
                ['文件', '行号', '标记'],
                $rows
            );

            $lines[] = '';
        }

        if($requiredFiles['missing'] !== []){
            $lines[] = '缺失文件：'
                . $this->inlineList($requiredFiles['missing'])
                . '。';
            $lines[] = '';
        }

        $lines[] = '### 4.4 CSS使用关系';
        $lines[] = '';
        $lines[] = '- CSS class定义：**'
            . $css['class_count']
            . '** 个。';
        $lines[] = '- 明确无使用候选：**'
            . $css['unused_count']
            . ' 项**。';
        $lines[] = '';

        if($css['unused_candidates'] !== []){
            $lines[] = '无使用候选：'
                . $this->inlineList($css['unused_candidates'])
                . '。';
            $lines[] = '';
        }
    }

    private function appendTestSection(array &$lines, array $results): void
    {
        $tests = $results['tests'];

        $lines[] = '## 5. 自动化测试报告';
        $lines[] = '';
        $lines[] = '- Unit Test：**' . $tests['unit_total'] . '**';
        $lines[] = '- Feature Test：**' . $tests['feature_total'] . '**';
        $lines[] = '- 静态发现测试总数：**' . $tests['discovered_total'] . '**';
        $lines[] = '- 实际运行测试总数：**' . $tests['total'] . '**';
        $lines[] = '- 通过：**' . $tests['passed_count'] . '**';
        $lines[] = '- 失败：**' . $tests['failed_count'] . '**';
        $lines[] = '- Runner退出码：`' . $tests['exit_code'] . '`';
        $lines[] = '';

        $rows = [];

        foreach($tests['classes'] as $class){
            $rows[] = [
                $class['type'],
                $class['class'],
                (string)$class['count'],
            ];
        }

        $this->appendTable(
            $lines,
            ['类型', '测试类', '测试数'],
            $rows
        );

        $lines[] = '';

        if(!$tests['passed']){
            $lines[] = '### 5.1 测试Runner输出';
            $lines[] = '';
            $lines[] = '```text';
            $lines[] = $this->safeCodeBlock($tests['output']);
            $lines[] = '```';
            $lines[] = '';
        }
    }

    private function appendBusinessRuleSection(array &$lines, array $results): void
    {
        $rules = $results['business_rules'];
        $rows = [];

        foreach($rules['items'] as $rule){
            $rows[] = [
                $rule['rule'],
                $this->guardStatus($rule['database_guard']),
                $this->guardStatus($rule['service_guard']),
                $this->guardStatus($rule['test_guard']),
                $rule['evidence'],
            ];
        }

        $lines[] = '## 6. 关键业务规则保障矩阵';
        $lines[] = '';

        $this->appendTable(
            $lines,
            [
                '业务规则',
                '数据库保障',
                'Service保障',
                '自动化测试',
                '证据',
            ],
            $rows
        );

        $lines[] = '';
        $lines[] = '> “数据库保障”为否不代表规则缺失，而是表示该规则主要由Service层和自动化测试保障；报告不会把部分关联约束夸大为数据库层完整业务保障。';
        $lines[] = '';
    }

    private function appendDatabaseSection(array &$lines, array $results): void
    {
        $database = $results['database'];

        $lines[] = '## 7. 数据库结构与时间检查';
        $lines[] = '';

        if(!$database['connected']){
            $lines[] = '**❌ 数据库连接失败。**';
            $lines[] = '';
            $lines[] = '原因：' . ($database['error'] ?? '未知错误');
            $lines[] = '';
            return;
        }

        $rows = [
            ['连接状态', '✅ 成功'],
            ['数据库', $database['database_name']],
            ['数据库版本', $database['version']],
            ['Schema目标表数', (string)count($database['schema_tables'])],
            ['实际表数', (string)count($database['actual_tables'])],
            [
                '缺失表',
                $database['missing_tables'] === []
                    ? '无'
                    : $this->inlineList($database['missing_tables']),
            ],
            [
                '额外表',
                $database['extra_tables'] === []
                    ? '无'
                    : $this->inlineList($database['extra_tables']),
            ],
        ];

        $this->appendTable(
            $lines,
            ['检查项', '结果'],
            $rows
        );

        $lines[] = '';
        $lines[] = 'Schema目标表：'
            . $this->inlineList($database['schema_tables'])
            . '。';
        $lines[] = '';

        $timeRows = [
            ['PHP时区', $database['php_timezone']],
            ['PHP当前时间', $database['php_now']],
            ['MariaDB global time_zone', (string)$database['global_timezone']],
            ['MariaDB session time_zone', (string)$database['session_timezone']],
            ['MariaDB NOW()', (string)$database['database_now']],
            ['MariaDB UTC_TIMESTAMP()', (string)$database['database_utc_now']],
            [
                '数据库UTC偏移',
                (string)$database['database_utc_offset_hours'] . ' 小时',
            ],
            [
                'PHP与数据库时间差',
                (string)$database['php_database_difference_seconds'] . ' 秒',
            ],
            [
                '时间一致性',
                $this->status($database['time_consistent']),
            ],
        ];

        $lines[] = '### 7.1 时间一致性';
        $lines[] = '';

        $this->appendTable(
            $lines,
            ['检查项', '结果'],
            $timeRows
        );

        $lines[] = '';
    }

    private function appendSecuritySection(array &$lines, array $results): void
    {
        $csrf = $results['security']['csrf'];
        $sql = $results['security']['sql'];
        $controls = $results['security']['controls'];
        $rows = [];

        foreach($controls['items'] as $item){
            $rows[] = [
                $item['name'],
                $this->status($item['passed']),
                $item['evidence'],
            ];
        }

        $lines[] = '## 8. 安全控制静态检查';
        $lines[] = '';

        $this->appendTable(
            $lines,
            ['检查项', '状态', '证据'],
            $rows
        );

        $lines[] = '';
        $lines[] = '补充结果：';
        $lines[] = '';
        $lines[] = '- POST入口CSRF覆盖：**'
            . $csrf['covered_count']
            . ' / '
            . $csrf['post_file_count']
            . '**。';
        $lines[] = '- SQL预处理调用：**'
            . $sql['prepare_count']
            . '** 处。';
        $lines[] = '- 直接 `query()` 调用：**'
            . $sql['query_count']
            . '** 处。';
        $lines[] = '';

        if($csrf['uncovered_files'] !== []){
            $lines[] = '未覆盖CSRF的POST入口：'
                . $this->inlineList($csrf['uncovered_files'])
                . '。';
            $lines[] = '';
        }

        if($sql['unexpected_query_files'] !== []){
            $lines[] = '发现未复核的直接 `query()` 调用文件：'
                . $this->inlineList($sql['unexpected_query_files'])
                . '。';
            $lines[] = '';
        }else{
            $lines[] = '当前直接 `query()` 调用文件均位于已复核清单内，静态检查未发现新增的未复核直接调用文件。';
            $lines[] = '';
        }

        $lines[] = '> 本节属于安全控制静态检查与相关自动化测试汇总，不等同于专业渗透测试或完整安全审计。';
        $lines[] = '';
    }

    private function appendEvidenceSection(array &$lines): void
    {
        $rows = [
            [
                'PHP语法',
                '对全部PHP文件执行 `php -l`',
                '失败数量为0',
            ],
            [
                'JavaScript语法',
                '对全部JavaScript文件执行 `node --check`',
                'Node.js可调用且失败数量为0',
            ],
            [
                '自动化测试',
                '`php tests/run.php`',
                '静态发现数量与实际运行数量一致，全部通过且退出码为0',
            ],
            [
                'PHP扩展与测试数据库',
                '测试Runner环境预检查',
                '`mbstring`、`mysqli` 与测试数据库连接正常',
            ],
            [
                '数据库结构',
                '解析Schema表名并与 `SHOW TABLES` 对照',
                'Schema目标表全部存在',
            ],
            [
                '临时标记',
                '扫描代码中的 `'
                    . $this->developmentMarkerLabel()
                    . '`',
                '数量为0',
            ],
            [
                'POST CSRF',
                '扫描POST入口及CSRF调用路径',
                '识别到的POST入口全部存在处理',
            ],
            [
                'SQL调用',
                '统计 `prepare()` / `query()` 并检查直接调用文件',
                '无未复核的新增直接调用文件',
            ],
            [
                'CSS使用关系',
                'class定义与PHP/JS使用关系扫描',
                '无明确无使用候选',
            ],
            [
                '安全控制',
                '核心类关键配置静态检查 + Unit/Feature Test',
                '定义范围内检查项满足',
            ],
            [
                '业务规则',
                'Schema、Service入口和测试方法三层交叉检查',
                '矩阵内规则满足既定保障条件',
            ],
        ];

        $lines[] = '## 9. 检查方法与证据来源';
        $lines[] = '';

        $this->appendTable(
            $lines,
            ['检查项目', '检查方式', '判定标准'],
            $rows
        );

        $lines[] = '';
    }

    private function appendManualCheckSection(array &$lines): void
    {
        $rows = [
            [
                '页面显示',
                '桌面与窄屏布局是否正常',
                '当前没有视觉回归测试',
            ],
            [
                '表单交互',
                '错误提示、按钮禁用和重复提交体验',
                '自动化测试主要验证后端行为',
            ],
            [
                '导航跳转',
                '登录、退出及权限跳转后的目标页面',
                '当前没有浏览器E2E测试',
            ],
            [
                'CSV下载',
                '文件名、编码及Excel实际打开效果',
                '依赖真实浏览器与桌面软件环境',
            ],
            [
                '页面筛选',
                '控件显示、重置按钮及URL参数保留',
                'Repository测试不验证完整页面交互',
            ],
            [
                '充电流程',
                '从开始充电到结束后的完整浏览器流程',
                '当前没有端到端浏览器测试',
            ],
            [
                '管理流程',
                '状态操作后的页面反馈与Flash消息',
                'Service测试不验证最终UI渲染',
            ],
            [
                '响应式布局',
                '700px、760px、900px等断点附近表现',
                'CSS静态扫描无法验证浏览器渲染',
            ],
        ];

        $lines[] = '## 10. 需人工检查项目';
        $lines[] = '';
        $lines[] = '以下项目不参与本报告的自动通过/失败判定。';
        $lines[] = '';

        $this->appendTable(
            $lines,
            ['分类', '需人工确认内容', '原因'],
            $rows
        );

        $lines[] = '';
    }

    private function appendLimitationsSection(array &$lines): void
    {
        $lines[] = '## 11. 已知限制与未覆盖范围';
        $lines[] = '';
        $lines[] = '- 本报告不包含压力测试、并发基准测试或性能评分。';
        $lines[] = '- 当前没有使用Xdebug或PCOV生成代码覆盖率，因此报告不提供覆盖率百分比。';
        $lines[] = '- 本报告不等同于专业渗透测试、动态漏洞扫描或完整第三方依赖CVE审计。';
        $lines[] = '- 项目使用轻量自定义测试框架；Feature Test通过重建测试数据库提高隔离性，但执行速度慢于共享Fixture模式。';
        $lines[] = '';
    }

    private function appendTable(array &$lines, array $headers, array $rows): void
    {
        $headers = array_map(
            [$this, 'escapeTableCell'],
            array_map(
                static fn(mixed $value): string => (string)$value,
                $headers
            )
        );

        $normalizedRows = [];

        foreach($rows as $row){
            $cells = array_map(
                [$this, 'escapeTableCell'],
                array_map(
                    static fn(mixed $value): string => (string)$value,
                    $row
                )
            );

            $cells = array_pad(
                $cells,
                count($headers),
                ''
            );

            $normalizedRows[] = array_slice(
                $cells,
                0,
                count($headers)
            );
        }

        $widths = [];

        foreach($headers as $index => $header){
            $widths[$index] = max(
                3,
                $this->displayWidth($header)
            );
        }

        foreach($normalizedRows as $row){
            foreach($row as $index => $cell){
                $widths[$index] = max(
                    $widths[$index],
                    $this->displayWidth($cell)
                );
            }
        }

        $lines[] = $this->buildTableRow(
            $headers,
            $widths
        );

        $lines[] = '| '
            . implode(
                ' | ',
                array_map(
                    static fn(int $width): string =>
                        str_repeat('-', $width),
                    $widths
                )
            )
            . ' |';

        foreach($normalizedRows as $row){
            $lines[] = $this->buildTableRow(
                $row,
                $widths
            );
        }
    }

    private function buildTableRow(array $cells, array $widths): string
    {
        $paddedCells = [];

        foreach($cells as $index => $cell){
            $missingWidth = $widths[$index]
                - $this->displayWidth($cell);

            $paddedCells[] = $cell
                . str_repeat(
                    ' ',
                    max(0, $missingWidth)
                );
        }

        return '| '
            . implode(' | ', $paddedCells)
            . ' |';
    }

    private function displayWidth(string $value): int
    {
        if(function_exists('mb_strwidth')){
            return mb_strwidth(
                $value,
                'UTF-8'
            );
        }

        return strlen($value);
    }

    private function developmentMarkerLabel(): string
    {
        return 'TO' . 'DO / '
            . 'FIX' . 'ME / '
            . 'HA' . 'CK';
    }

    private function status(?bool $passed): string
    {
        if($passed === null){
            return '⚪ 未执行';
        }

        return $passed
            ? '✅ 通过'
            : '❌ 未通过';
    }

    private function guardStatus(bool $guarded): string
    {
        return $guarded ? '✅' : '—';
    }

    private function javascriptSummary(array $javascript): string
    {
        if(!$javascript['available']){
            return 'Node.js不可用';
        }

        return $javascript['passed_count']
            . ' / '
            . $javascript['total'];
    }

    private function databaseSummary(array $database): string
    {
        if(!$database['connected']){
            return '连接失败';
        }

        return count($database['actual_tables'])
            . ' 张表，时间差 '
            . $database['php_database_difference_seconds']
            . ' 秒';
    }

    private function inlineList(array $items): string
    {
        if($items === []){
            return '无';
        }

        return implode(
            '、',
            array_map(
                fn(mixed $item): string => '`'
                    . $this->escapeInlineCode((string)$item)
                    . '`',
                $items
            )
        );
    }

    private function escapeTableCell(string $value): string
    {
        return str_replace(
            [
                '|',
                "\r\n",
                "\n",
                "\r",
            ],
            [
                '\|',
                '<br>',
                '<br>',
                '<br>',
            ],
            $value
        );
    }

    private function escapeInlineCode(string $value): string
    {
        return str_replace(
            '`',
            '\`',
            $value
        );
    }

    private function safeCodeBlock(string $value): string
    {
        return str_replace(
            '```',
            '`` `',
            $value
        );
    }
}