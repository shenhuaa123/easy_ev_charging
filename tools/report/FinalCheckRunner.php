<?php

declare(strict_types=1);

namespace Tools\Report;

use RuntimeException;
use Throwable;

final class FinalCheckRunner
{
    private string $rootDirectory;

    private float $startedAt = 0.0;

    public function __construct(string $rootDirectory)
    {
        $resolvedRoot = realpath($rootDirectory);

        if($resolvedRoot === false || !is_dir($resolvedRoot)){
            throw new RuntimeException('项目根目录不存在：' . $rootDirectory);
        }

        $this->rootDirectory = rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
    }

    public function run(): array
    {
        $this->startedAt = microtime(true);

        $structure = $this->collectProjectStructure();
        $phpSyntax = $this->checkPhpSyntax();
        $javascriptSyntax = $this->checkJavaScriptSyntax();
        $testDistribution = $this->analyzeTestDistribution();
        $automatedTests = $this->runAutomatedTests($testDistribution);
        $markers = $this->scanDevelopmentMarkers();
        $requiredFiles = $this->checkRequiredFiles();
        $csrf = $this->scanCsrfCoverage();
        $sql = $this->scanSqlUsage();
        $css = $this->scanCssUsage();
        $database = $this->inspectDatabase();
        $securityControls = $this->inspectSecurityControls($testDistribution);
        $businessRules = $this->buildBusinessRuleMatrix($testDistribution);

        $checks = [
            'PHP语法检查' => $phpSyntax['passed'],
            'JavaScript语法检查' => $javascriptSyntax['passed'],
            '自动化测试' => $automatedTests['passed'],
            '临时开发标记扫描' => $markers['passed'],
            '必要文件检查' => $requiredFiles['passed'],
            '数据库结构与时间检查' => $database['passed'],
            'POST入口CSRF覆盖' => $csrf['passed'],
            'SQL直接调用复查' => $sql['passed'],
            'CSS使用关系扫描' => $css['passed'],
            '安全控制静态检查' => $securityControls['passed'],
            '关键业务规则保障检查' => $businessRules['passed'],
        ];

        $failedChecks = [];

        foreach($checks as $name => $passed){
            if(!$passed){
                $failedChecks[] = $name;
            }
        }

        return [
            'meta' => [
                'generated_at' => date('Y-m-d H:i:s'),
                'php_version' => PHP_VERSION,
                'php_timezone' => date_default_timezone_get(),
                'elapsed_seconds' => round(microtime(true) - $this->startedAt, 2),
            ],
            'structure' => $structure,
            'syntax' => [
                'php' => $phpSyntax,
                'javascript' => $javascriptSyntax,
            ],
            'tests' => $automatedTests,
            'markers' => $markers,
            'required_files' => $requiredFiles,
            'database' => $database,
            'security' => [
                'csrf' => $csrf,
                'sql' => $sql,
                'controls' => $securityControls,
            ],
            'quality' => [
                'css' => $css,
            ],
            'business_rules' => $businessRules,
            'overall' => [
                'passed' => $failedChecks === [],
                'checks' => $checks,
                'failed_checks' => $failedChecks,
            ],
        ];
    }

    private function collectProjectStructure(): array
    {
        return [
            'php_files' => count($this->findFiles($this->rootDirectory, ['php'])),
            'app_class_files' => count($this->findFiles($this->path('app'), ['php'])),
            'public_php_pages' => count($this->findFiles($this->path('public'), ['php'])),
            'view_files' => count($this->findFiles($this->path('views'), ['php'])),
            'css_files' => count($this->findFiles($this->path('public/assets/css'), ['css'])),
            'javascript_files' => count($this->findFiles($this->path('public/assets/js'), ['js'])),
            'sql_files' => count($this->findFiles($this->path('database'), ['sql'])),
            'cli_tools' => count($this->findFiles($this->path('tools'), ['php'], false)),
            'test_files' => count($this->findTestFiles()),
        ];
    }

    private function checkPhpSyntax(): array
    {
        $files = $this->findFiles($this->rootDirectory, ['php']);
        $failures = [];

        foreach($files as $file){
            $result = $this->runCommand([PHP_BINARY, '-l', $file]);

            if($result['exit_code'] !== 0){
                $failures[] = [
                    'file' => $this->relativePath($file),
                    'message' => trim(
                        $result['stderr'] !== ''
                            ? $result['stderr']
                            : $result['stdout']
                    ),
                ];
            }
        }

        return [
            'total' => count($files),
            'passed_count' => count($files) - count($failures),
            'failed_count' => count($failures),
            'failures' => $failures,
            'passed' => $failures === [],
        ];
    }

    private function checkJavaScriptSyntax(): array
    {
        $files = $this->findFiles(
            $this->path('public/assets/js'),
            ['js']
        );

        $nodeResult = $this->runCommand([
            'node',
            '--version',
        ]);

        if($nodeResult['exit_code'] !== 0){
            return [
                'available' => false,
                'node_version' => null,
                'total' => count($files),
                'passed_count' => 0,
                'failed_count' => count($files),
                'failures' => [],
                'message' => '当前环境无法调用Node.js。',
                'passed' => false,
            ];
        }

        $nodeVersion = trim(
            $nodeResult['stdout'] !== ''
                ? $nodeResult['stdout']
                : $nodeResult['stderr']
        );

        $failures = [];

        foreach($files as $file){
            $result = $this->runCommand([
                'node',
                '--check',
                $file,
            ]);

            if($result['exit_code'] !== 0){
                $failures[] = [
                    'file' => $this->relativePath($file),
                    'message' => trim(
                        $result['stderr'] !== ''
                            ? $result['stderr']
                            : $result['stdout']
                    ),
                ];
            }
        }

        return [
            'available' => true,
            'node_version' => $nodeVersion,
            'total' => count($files),
            'passed_count' => count($files) - count($failures),
            'failed_count' => count($failures),
            'failures' => $failures,
            'message' => null,
            'passed' => $failures === [],
        ];
    }

    private function analyzeTestDistribution(): array
    {
        $classes = [];
        $unitTotal = 0;
        $featureTotal = 0;
        $methodNames = [];

        foreach($this->findTestFiles() as $file){
            $content = $this->readFile($file);

            preg_match_all(
                '/public\s+function\s+(test[A-Za-z0-9_]+)\s*\(/',
                $content,
                $matches
            );

            $count = count($matches[1] ?? []);
            $relativePath = $this->relativePath($file);
            $type = str_contains(
                str_replace('\\', '/', $relativePath),
                '/Unit/'
            )
                ? 'Unit'
                : 'Feature';

            $className = pathinfo($file, PATHINFO_FILENAME);

            if($type === 'Unit'){
                $unitTotal += $count;
            }else{
                $featureTotal += $count;
            }

            foreach($matches[1] ?? [] as $methodName){
                $methodNames[] = $className . '::' . $methodName;
            }

            $classes[] = [
                'type' => $type,
                'class' => $className,
                'count' => $count,
            ];
        }

        usort(
            $classes,
            static fn(array $left, array $right): int =>
                [$left['type'], $left['class']]
                <=>
                [$right['type'], $right['class']]
        );

        return [
            'unit_total' => $unitTotal,
            'feature_total' => $featureTotal,
            'total' => $unitTotal + $featureTotal,
            'classes' => $classes,
            'method_names' => $methodNames,
        ];
    }

    private function runAutomatedTests(array $distribution): array
    {
        $result = $this->runCommand(
            [
                PHP_BINARY,
                $this->path('tests/run.php'),
            ],
            $this->rootDirectory
        );

        $output = trim(
            $result['stdout']
            . (
                $result['stderr'] !== ''
                    ? PHP_EOL . $result['stderr']
                    : ''
            )
        );

        $total = $this->extractInteger(
            $output,
            '/测试总数：\s*(\d+)/u'
        );

        $passedCount = $this->extractInteger(
            $output,
            '/通过数量：\s*(\d+)/u'
        );

        $failedCount = $this->extractInteger(
            $output,
            '/失败数量：\s*(\d+)/u'
        );

        $environmentFailure = str_contains(
            $output,
            '[ENV FAIL]'
        );

        $passed = $result['exit_code'] === 0
            && !$environmentFailure
            && $total !== null
            && $passedCount !== null
            && $failedCount === 0
            && $total === $distribution['total']
            && $passedCount === $total;

        return [
            'exit_code' => $result['exit_code'],
            'total' => $total ?? 0,
            'passed_count' => $passedCount ?? 0,
            'failed_count' => $failedCount ?? 0,
            'unit_total' => $distribution['unit_total'],
            'feature_total' => $distribution['feature_total'],
            'discovered_total' => $distribution['total'],
            'classes' => $distribution['classes'],
            'method_names' => $distribution['method_names'],
            'environment_failure' => $environmentFailure,
            'output' => $output,
            'passed' => $passed,
        ];
    }

    private function scanDevelopmentMarkers(): array
    {
        $directories = [
            'app',
            'public',
            'views',
            'tests',
            'tools',
            'database',
        ];

        $extensions = [
            'php',
            'js',
            'css',
            'sql',
        ];

        $markerPattern = '/\b('
            . implode('|', [
                'TO' . 'DO',
                'FIX' . 'ME',
                'HA' . 'CK',
            ])
            . ')\b/i';

        $items = [];

        foreach($directories as $directory){
            foreach(
                $this->findFiles(
                    $this->path($directory),
                    $extensions
                ) as $file
            ){
                $lines = file(
                    $file,
                    FILE_IGNORE_NEW_LINES
                );

                if($lines === false){
                    continue;
                }

                foreach($lines as $lineNumber => $line){
                    if(
                        preg_match(
                            $markerPattern,
                            $line,
                            $matches
                        ) !== 1
                    ){
                        continue;
                    }

                    $items[] = [
                        'file' => $this->relativePath($file),
                        'line' => $lineNumber + 1,
                        'marker' => strtoupper($matches[1]),
                    ];
                }
            }
        }

        return [
            'count' => count($items),
            'items' => $items,
            'passed' => $items === [],
        ];
    }

    private function checkRequiredFiles(): array
    {
        $requiredFiles = [
            'bootstrap.php',
            'config/database.php',
            'database/easy_ev_charging_system_schema.sql',
            'database/easy_ev_charging_system_demo_data.sql',
            'tests/run.php',
            'tests/database_test.php',
            'storage/.htaccess',
            'public/assets/css/common.css',
            'public/assets/js/form_submit_lock.js',
        ];

        $rows = [];
        $missing = [];

        foreach($requiredFiles as $relativePath){
            $exists = is_file(
                $this->path($relativePath)
            );

            $rows[] = [
                'file' => $relativePath,
                'exists' => $exists,
            ];

            if(!$exists){
                $missing[] = $relativePath;
            }
        }

        return [
            'total' => count($requiredFiles),
            'missing_count' => count($missing),
            'missing' => $missing,
            'files' => $rows,
            'passed' => $missing === [],
        ];
    }

    private function scanCsrfCoverage(): array
    {
        $postFiles = [];
        $coveredFiles = [];
        $uncoveredFiles = [];

        foreach(
            $this->findFiles(
                $this->path('public'),
                ['php']
            ) as $file
        ){
            $content = $this->readFile($file);

            if(
                !str_contains($content, 'REQUEST_METHOD')
                || !preg_match('/[\'"]POST[\'"]/', $content)
            ){
                continue;
            }

            $relativePath = $this->relativePath($file);
            $postFiles[] = $relativePath;

            $covered = preg_match(
                '/\$csrf\s*->\s*validate\s*\(/',
                $content
            ) === 1
                || preg_match(
                    '/->\s*isValidCsrf\s*\(/',
                    $content
                ) === 1
                || preg_match(
                    '/->\s*validateCsrfOrRedirect\s*\(/',
                    $content
                ) === 1;

            if($covered){
                $coveredFiles[] = $relativePath;
            }else{
                $uncoveredFiles[] = $relativePath;
            }
        }

        sort($postFiles);
        sort($coveredFiles);
        sort($uncoveredFiles);

        return [
            'post_file_count' => count($postFiles),
            'covered_count' => count($coveredFiles),
            'uncovered_count' => count($uncoveredFiles),
            'post_files' => $postFiles,
            'covered_files' => $coveredFiles,
            'uncovered_files' => $uncoveredFiles,
            'passed' => $postFiles !== []
                && $uncoveredFiles === [],
        ];
    }

    private function scanSqlUsage(): array
    {
        $allowedDirectQueryFiles = [
            'app/Repositories/AdminDashboardRepository.php',
            'app/Repositories/LocationRepository.php',
            'app/Repositories/LoginAttemptRepository.php',
        ];

        $prepareCount = 0;
        $queryCount = 0;
        $queryFiles = [];

        foreach(
            $this->findFiles(
                $this->path('app'),
                ['php']
            ) as $file
        ){
            $content = $this->readFile($file);

            $prepareMatches = preg_match_all(
                '/->\s*prepare\s*\(/',
                $content
            );

            $queryMatches = preg_match_all(
                '/->\s*query\s*\(/',
                $content
            );

            $prepareCount += $prepareMatches === false
                ? 0
                : $prepareMatches;

            $queryCount += $queryMatches === false
                ? 0
                : $queryMatches;

            if(
                $queryMatches !== false
                && $queryMatches > 0
            ){
                $queryFiles[] = $this->relativePath($file);
            }
        }

        sort($queryFiles);
        sort($allowedDirectQueryFiles);

        $unexpectedQueryFiles = array_values(
            array_diff(
                $queryFiles,
                $allowedDirectQueryFiles
            )
        );

        return [
            'prepare_count' => $prepareCount,
            'query_count' => $queryCount,
            'query_files' => $queryFiles,
            'allowed_query_files' => $allowedDirectQueryFiles,
            'unexpected_query_files' => $unexpectedQueryFiles,
            'passed' => $unexpectedQueryFiles === [],
        ];
    }

    private function scanCssUsage(): array
    {
        $cssFiles = $this->findFiles(
            $this->path('public/assets/css'),
            ['css']
        );

        $classes = [];

        foreach($cssFiles as $file){
            $content = $this->readFile($file);

            preg_match_all(
                '/(?<![A-Za-z0-9_-])\.([A-Za-z_][A-Za-z0-9_-]*)/',
                $content,
                $matches
            );

            foreach($matches[1] ?? [] as $className){
                $classes[$className] = true;
            }
        }

        $usageContent = '';

        foreach(
            ['app', 'public', 'views', 'tests', 'tools']
            as $directory
        ){
            foreach(
                $this->findFiles(
                    $this->path($directory),
                    ['php', 'js']
                ) as $file
            ){
                $usageContent .= PHP_EOL
                    . $this->readFile($file);
            }
        }

        $unusedCandidates = [];
        $classNames = array_keys($classes);

        sort($classNames);

        foreach($classNames as $className){
            $pattern = '/(?<![A-Za-z0-9_-])'
                . preg_quote($className, '/')
                . '(?![A-Za-z0-9_-])/';

            if(preg_match($pattern, $usageContent) !== 1){
                $unusedCandidates[] = $className;
            }
        }

        return [
            'class_count' => count($classNames),
            'unused_count' => count($unusedCandidates),
            'unused_candidates' => $unusedCandidates,
            'passed' => $unusedCandidates === [],
        ];
    }

    private function inspectDatabase(): array
    {
        $schemaFile = $this->path(
            'database/easy_ev_charging_system_schema.sql'
        );

        $schemaTables = [];

        if(is_file($schemaFile)){
            $schemaContent = $this->readFile($schemaFile);

            preg_match_all(
                '/CREATE\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i',
                $schemaContent,
                $matches
            );

            $schemaTables = array_values(
                array_unique($matches[1] ?? [])
            );

            sort($schemaTables);
        }

        $baseResult = [
            'connected' => false,
            'version' => null,
            'database_name' => null,
            'schema_tables' => $schemaTables,
            'actual_tables' => [],
            'missing_tables' => $schemaTables,
            'extra_tables' => [],
            'global_timezone' => null,
            'session_timezone' => null,
            'database_now' => null,
            'database_utc_now' => null,
            'database_utc_offset_seconds' => null,
            'database_utc_offset_hours' => null,
            'php_now' => date('Y-m-d H:i:s'),
            'php_timezone' => date_default_timezone_get(),
            'php_database_difference_seconds' => null,
            'time_consistent' => false,
            'error' => null,
            'passed' => false,
        ];

        if(!extension_loaded('mysqli')){
            $baseResult['error'] = '当前PHP环境未加载mysqli扩展。';

            return $baseResult;
        }

        $configPath = $this->path(
            'config/database.php'
        );

        if(!is_file($configPath)){
            $baseResult['error'] = '缺少数据库配置文件。';

            return $baseResult;
        }

        $config = require $configPath;

        try{
            $connection = new \mysqli(
                (string)($config['host'] ?? ''),
                (string)($config['username'] ?? ''),
                (string)($config['password'] ?? ''),
                (string)($config['database'] ?? ''),
                (int)($config['port'] ?? 3306)
            );

            if($connection->connect_error){
                throw new RuntimeException(
                    $connection->connect_error
                );
            }

            $charset = (string)(
                $config['charset']
                ?? 'utf8mb4'
            );

            if(!$connection->set_charset($charset)){
                throw new RuntimeException(
                    '设置数据库字符集失败。'
                );
            }

            $actualTables = [];
            $tableResult = $connection->query(
                'SHOW TABLES'
            );

            if($tableResult === false){
                throw new RuntimeException(
                    '读取数据库表清单失败。'
                );
            }

            while($row = $tableResult->fetch_row()){
                $actualTables[] = (string)$row[0];
            }

            $tableResult->free();
            sort($actualTables);

            $timeResult = $connection->query(
                'SELECT '
                . '@@global.time_zone AS global_timezone, '
                . '@@session.time_zone AS session_timezone, '
                . 'NOW() AS database_now, '
                . 'UTC_TIMESTAMP() AS database_utc_now, '
                . 'TIMESTAMPDIFF('
                . 'SECOND, UTC_TIMESTAMP(), NOW()'
                . ') AS offset_seconds'
            );

            if($timeResult === false){
                throw new RuntimeException(
                    '读取数据库时间信息失败。'
                );
            }

            $timeRow = $timeResult->fetch_assoc();
            $timeResult->free();

            if(!is_array($timeRow)){
                throw new RuntimeException(
                    '数据库时间信息为空。'
                );
            }

            $databaseNow = (string)$timeRow['database_now'];
            $databaseTimestamp = strtotime($databaseNow);
            $phpTimestamp = time();

            $differenceSeconds = $databaseTimestamp === false
                ? null
                : abs(
                    $phpTimestamp
                    - $databaseTimestamp
                );

            $offsetSeconds = (int)$timeRow['offset_seconds'];

            $missingTables = array_values(
                array_diff(
                    $schemaTables,
                    $actualTables
                )
            );

            $extraTables = array_values(
                array_diff(
                    $actualTables,
                    $schemaTables
                )
            );

            $timeConsistent = $differenceSeconds !== null
                && $differenceSeconds <= 120;

            $result = [
                'connected' => true,
                'version' => $connection->server_info,
                'database_name' => (string)(
                    $config['database']
                    ?? ''
                ),
                'schema_tables' => $schemaTables,
                'actual_tables' => $actualTables,
                'missing_tables' => $missingTables,
                'extra_tables' => $extraTables,
                'global_timezone' => (string)$timeRow['global_timezone'],
                'session_timezone' => (string)$timeRow['session_timezone'],
                'database_now' => $databaseNow,
                'database_utc_now' => (string)$timeRow['database_utc_now'],
                'database_utc_offset_seconds' => $offsetSeconds,
                'database_utc_offset_hours' => round(
                    $offsetSeconds / 3600,
                    2
                ),
                'php_now' => date('Y-m-d H:i:s'),
                'php_timezone' => date_default_timezone_get(),
                'php_database_difference_seconds' => $differenceSeconds,
                'time_consistent' => $timeConsistent,
                'error' => null,
                'passed' => $missingTables === []
                    && $timeConsistent,
            ];

            $connection->close();

            return $result;
        }catch(Throwable $exception){
            $baseResult['error'] = $exception->getMessage();

            return $baseResult;
        }
    }

    private function inspectSecurityControls(
        array $testDistribution
    ): array {
        $sessionContent = $this->readFile(
            $this->path('app/Core/Session.php')
        );

        $csrfContent = $this->readFile(
            $this->path('app/Core/Csrf.php')
        );

        $headersContent = $this->readFile(
            $this->path('app/Core/SecurityHeaders.php')
        );

        $loggerContent = $this->readFile(
            $this->path('app/Core/Logger.php')
        );

        $throttleContent = $this->readFile(
            $this->path('app/Services/LoginThrottleService.php')
        );

        $authGuardContent = $this->readFile(
            $this->path('app/Core/AuthGuard.php')
        );

        $csvContent = $this->readFile(
            $this->path('app/Services/CsvExportService.php')
        );

        $bootstrapContent = $this->readFile(
            $this->path('bootstrap.php')
        );

        $testBootstrapContent = $this->readFile(
            $this->path('tests/bootstrap.php')
        );

        $clearLogsContent = $this->readFile(
            $this->path('tools/clear_logs.php')
        );

        $methodNames = $testDistribution['method_names'];

        $controls = [
            $this->control(
                'Session安全参数',
                $this->containsAll(
                    $sessionContent,
                    [
                        "session.use_strict_mode', '1'",
                        "session.use_only_cookies', '1'",
                        "session.use_trans_sid', '0'",
                        "'httponly' => true",
                        "'samesite' => 'Lax'",
                        'IDLE_TIMEOUT_SECONDS = 1800',
                        'ABSOLUTE_TIMEOUT_SECONDS = 28800',
                        'REGENERATE_INTERVAL_SECONDS = 900',
                    ]
                ),
                '严格模式、仅Cookie、禁用URL Session、HttpOnly、SameSite以及超时和Session ID更新间隔均已配置。'
            ),
            $this->control(
                'CSRF核心机制',
                $this->containsAll(
                    $csrfContent,
                    [
                        'TOKEN_BYTES = 32',
                        'random_bytes',
                        'hash_equals',
                        'public function regenerate',
                    ]
                )
                && $this->hasTestMethod(
                    $methodNames,
                    'CsrfTest::testRegenerateReplacesCurrentToken'
                ),
                '使用32字节安全随机数、hash_equals比较，并存在Token重建行为测试。'
            ),
            $this->control(
                'HTTP安全响应头',
                $this->containsAll(
                    $headersContent,
                    [
                        'X-Content-Type-Options: nosniff',
                        'X-Frame-Options: DENY',
                        'Referrer-Policy: same-origin',
                        'Content-Security-Policy:',
                        'Strict-Transport-Security:',
                    ]
                ),
                '已配置内容类型保护、点击劫持保护、Referrer Policy、CSP和条件式HSTS。'
            ),
            $this->control(
                '日志敏感字段处理',
                $this->containsAll(
                    $loggerContent,
                    [
                        "'password'",
                        "'password_hash'",
                        "'password_confirmation'",
                        "'_csrf_token'",
                        "'cookie'",
                        "'authorization'",
                        'MAX_STRING_LENGTH = 1000',
                    ]
                ),
                '日志包含敏感键过滤和字符串长度限制。'
            ),
            $this->control(
                '登录频率限制',
                $this->containsAll(
                    $throttleContent,
                    [
                        'ACCOUNT_MAX_ATTEMPTS = 5',
                        'IP_MAX_ATTEMPTS = 20',
                        'WINDOW_SECONDS = 900',
                        'LOCK_SECONDS = 900',
                    ]
                )
                && $this->hasTestMethod(
                    $methodNames,
                    'LoginThrottleServiceTest::testAccountIsBlockedAfterFifthFailure'
                )
                && $this->hasTestMethod(
                    $methodNames,
                    'LoginThrottleServiceTest::testIpIsBlockedAcrossDifferentUsernamesAfterTwentiethFailure'
                ),
                '账户5次、IP 20次、15分钟窗口与锁定策略存在，并有对应Feature Test。'
            ),
            $this->control(
                '认证与角色访问控制',
                $this->containsAll(
                    $authGuardContent,
                    [
                        'public function requireLogin',
                        'public function requireAdmin',
                        'public function requireUser',
                        'matchesCredentialFingerprint',
                    ]
                ),
                '登录、管理员、普通用户访问控制以及凭据指纹校验路径存在。'
            ),
            $this->control(
                'CSV公式注入防护',
                str_contains(
                    $csvContent,
                    "preg_match('/\\A[=+\\-@]/u'"
                )
                && $this->hasTestMethod(
                    $methodNames,
                    'CsvExportServiceTest::testDownloadEscapesSpreadsheetFormulaCells'
                ),
                '危险公式前缀会转为文本，并有真实导出行为Unit Test。'
            ),
            $this->control(
                '项目业务时区',
                str_contains(
                    $bootstrapContent,
                    "date_default_timezone_set('Asia/Shanghai')"
                )
                && str_contains(
                    $testBootstrapContent,
                    "date_default_timezone_set('Asia/Shanghai')"
                )
                && str_contains(
                    $clearLogsContent,
                    "date_default_timezone_set('Asia/Shanghai')"
                ),
                'Web入口、测试入口和日志清理工具均明确使用Asia/Shanghai。'
            ),
            $this->control(
                '测试日志隔离',
                str_contains(
                    $testBootstrapContent,
                    "const APP_ENV = 'test'"
                )
                && str_contains(
                    $loggerContent,
                    'TEST_LOG_SUBDIRECTORY'
                )
                && str_contains(
                    $loggerContent,
                    "constant('APP_ENV') === 'test'"
                ),
                '测试环境通过APP_ENV=test分流到独立测试日志目录。'
            ),
        ];

        $passed = true;

        foreach($controls as $control){
            if(!$control['passed']){
                $passed = false;
                break;
            }
        }

        return [
            'items' => $controls,
            'passed_count' => count(
                array_filter(
                    $controls,
                    static fn(array $item): bool =>
                        $item['passed']
                )
            ),
            'total' => count($controls),
            'passed' => $passed,
        ];
    }

    private function buildBusinessRuleMatrix(
        array $testDistribution
    ): array {
        $schemaContent = $this->readFile(
            $this->path(
                'database/easy_ev_charging_system_schema.sql'
            )
        );

        $chargeServiceContent = $this->readFile(
            $this->path(
                'app/Services/ChargeRecordService.php'
            )
        );

        $userServiceContent = $this->readFile(
            $this->path(
                'app/Services/UserService.php'
            )
        );

        $locationServiceContent = $this->readFile(
            $this->path(
                'app/Services/LocationService.php'
            )
        );

        $stationServiceContent = $this->readFile(
            $this->path(
                'app/Services/ChargingStationService.php'
            )
        );

        $reviewServiceContent = $this->readFile(
            $this->path(
                'app/Services/LocationReviewService.php'
            )
        );

        $methodNames = $testDistribution['method_names'];

        $rules = [
            $this->businessRule(
                '同一用户只能有一条进行中订单',
                str_contains(
                    $schemaContent,
                    'uk_charge_records_one_active_per_user'
                ),
                str_contains(
                    $chargeServiceContent,
                    'startCharging'
                ),
                $this->hasTestMethod(
                    $methodNames,
                    'ChargeRecordServiceTest::testStartChargingRejectsDuplicateActiveUserOrder'
                ),
                '生成列唯一约束 + ChargeRecordService + Feature Test'
            ),
            $this->businessRule(
                '同一充电桩只能有一条进行中订单',
                str_contains(
                    $schemaContent,
                    'uk_charge_records_one_active_per_station'
                ),
                str_contains(
                    $chargeServiceContent,
                    'startCharging'
                ),
                $this->hasTestMethod(
                    $methodNames,
                    'ChargeRecordServiceTest::testStartChargingRejectsOccupiedStation'
                ),
                '生成列唯一约束 + ChargeRecordService + Feature Test'
            ),
            $this->businessRule(
                '停用用户不能开始充电',
                false,
                str_contains(
                    $chargeServiceContent,
                    'startCharging'
                ),
                $this->hasTestMethod(
                    $methodNames,
                    'ChargeRecordServiceTest::testStartChargingRejectsDisabledUser'
                ),
                'ChargeRecordService状态校验 + Feature Test'
            ),
            $this->businessRule(
                '有活动订单的用户不能被停用',
                false,
                str_contains(
                    $userServiceContent,
                    'updateStatus'
                ),
                $this->hasTestMethod(
                    $methodNames,
                    'UserServiceTest::testAdminCannotDisableUserWithActiveChargeRecord'
                ),
                'UserService业务约束 + Feature Test'
            ),
            $this->businessRule(
                '站点状态变更联动充电桩状态',
                false,
                str_contains(
                    $locationServiceContent,
                    'updateStatus'
                ),
                $this->hasTestMethod(
                    $methodNames,
                    'LocationAndStationServiceTest::testLocationMaintenanceSyncsActiveStationsToMaintenance'
                )
                && $this->hasTestMethod(
                    $methodNames,
                    'LocationAndStationServiceTest::testLocationInactiveSyncsActiveAndMaintenanceStationsToInactive'
                ),
                'LocationService事务逻辑 + 双方向Feature Test'
            ),
            $this->businessRule(
                '活动订单期间限制充电桩硬件字段修改',
                false,
                str_contains(
                    $stationServiceContent,
                    'update'
                ),
                $this->hasTestMethod(
                    $methodNames,
                    'LocationAndStationServiceTest::testActiveChargeRestrictsHardwareChangesButAllowsNameAndRateChanges'
                ),
                'ChargingStationService字段边界 + Feature Test'
            ),
            $this->businessRule(
                '订单时间和结算字段保持一致',
                str_contains(
                    $schemaContent,
                    'chk_charge_records_time_order'
                )
                && str_contains(
                    $schemaContent,
                    'chk_charge_records_completed_data'
                ),
                str_contains(
                    $chargeServiceContent,
                    'ChargeBillingCalculator'
                ),
                $this->hasTestMethod(
                    $methodNames,
                    'ChargeBillingCalculatorTest::testCalculateRejectsEndTimeBeforeStartTime'
                )
                && $this->hasTestMethod(
                    $methodNames,
                    'ChargeRecordServiceTest::testFinishChargingCompletesOwnOrder'
                ),
                'CHECK约束 + 统一计费器 + Unit/Feature Test'
            ),
            $this->businessRule(
                '用户必须有完成或异常结束订单才能评价',
                false,
                str_contains(
                    $reviewServiceContent,
                    'saveUserReview'
                ),
                $this->hasTestMethod(
                    $methodNames,
                    'LocationReviewServiceTest::testSaveUserReviewRejectsUserWithoutCompletedOrder'
                )
                && $this->hasTestMethod(
                    $methodNames,
                    'LocationReviewServiceTest::testUserReviewContextAllowsReviewAfterCompletedOrder'
                ),
                'LocationReviewService资格校验 + Feature Test'
            ),
            $this->businessRule(
                '同一用户对同一站点只保留一条评价记录',
                str_contains(
                    $schemaContent,
                    'uk_location_reviews_user_location'
                ),
                str_contains(
                    $reviewServiceContent,
                    'saveUserReview'
                ),
                $this->hasTestMethod(
                    $methodNames,
                    'LocationReviewServiceTest::testSaveUserReviewUpdatesExistingReviewInsteadOfCreatingAnother'
                ),
                '唯一约束 + 更新分支 + Feature Test'
            ),
            $this->businessRule(
                '登录失败同时执行账户级和IP级限制',
                false,
                true,
                $this->hasTestMethod(
                    $methodNames,
                    'LoginThrottleServiceTest::testAccountIsBlockedAfterFifthFailure'
                )
                && $this->hasTestMethod(
                    $methodNames,
                    'LoginThrottleServiceTest::testIpIsBlockedAcrossDifferentUsernamesAfterTwentiethFailure'
                ),
                '双层限制Service + Feature Test'
            ),
        ];

        $passed = true;

        foreach($rules as $rule){
            if(!$rule['passed']){
                $passed = false;
                break;
            }
        }

        return [
            'items' => $rules,
            'passed_count' => count(
                array_filter(
                    $rules,
                    static fn(array $item): bool =>
                        $item['passed']
                )
            ),
            'total' => count($rules),
            'passed' => $passed,
        ];
    }

    private function control(
        string $name,
        bool $passed,
        string $evidence
    ): array {
        return [
            'name' => $name,
            'passed' => $passed,
            'evidence' => $evidence,
        ];
    }

    private function businessRule(
        string $rule,
        bool $databaseGuard,
        bool $serviceGuard,
        bool $testGuard,
        string $evidence
    ): array {
        return [
            'rule' => $rule,
            'database_guard' => $databaseGuard,
            'service_guard' => $serviceGuard,
            'test_guard' => $testGuard,
            'evidence' => $evidence,
            'passed' => $serviceGuard
                && $testGuard,
        ];
    }

    private function hasTestMethod(
        array $methodNames,
        string $expected
    ): bool {
        return in_array(
            $expected,
            $methodNames,
            true
        );
    }

    private function containsAll(
        string $content,
        array $needles
    ): bool {
        foreach($needles as $needle){
            if(!str_contains($content, $needle)){
                return false;
            }
        }

        return true;
    }

    private function extractInteger(
        string $content,
        string $pattern
    ): ?int {
        if(
            preg_match(
                $pattern,
                $content,
                $matches
            ) !== 1
        ){
            return null;
        }

        return (int)$matches[1];
    }

    private function findTestFiles(): array
    {
        $files = array_merge(
            $this->findFiles(
                $this->path('tests/Unit'),
                ['php']
            ),
            $this->findFiles(
                $this->path('tests/Feature'),
                ['php']
            )
        );

        $files = array_values(
            array_filter(
                $files,
                static fn(string $file): bool =>
                    str_ends_with(
                        $file,
                        'Test.php'
                    )
            )
        );

        sort($files);

        return $files;
    }

    private function findFiles(
        string $directory,
        array $extensions,
        bool $recursive = true
    ): array {
        if(!is_dir($directory)){
            return [];
        }

        $normalizedExtensions = array_map(
            'strtolower',
            $extensions
        );

        $files = [];

        if(!$recursive){
            $entries = scandir($directory);

            if($entries === false){
                return [];
            }

            foreach($entries as $entry){
                $path = $directory
                    . DIRECTORY_SEPARATOR
                    . $entry;

                if(!is_file($path)){
                    continue;
                }

                $extension = strtolower(
                    pathinfo(
                        $path,
                        PATHINFO_EXTENSION
                    )
                );

                if(
                    in_array(
                        $extension,
                        $normalizedExtensions,
                        true
                    )
                ){
                    $files[] = $path;
                }
            }

            sort($files);

            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach($iterator as $fileInfo){
            if(!$fileInfo->isFile()){
                continue;
            }

            $path = $fileInfo->getPathname();

            $extension = strtolower(
                pathinfo(
                    $path,
                    PATHINFO_EXTENSION
                )
            );

            if(
                in_array(
                    $extension,
                    $normalizedExtensions,
                    true
                )
            ){
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    private function runCommand(
        array $command,
        ?string $workingDirectory = null
    ): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];

        $errorMessage = null;

        set_error_handler(
            static function(
                int $severity,
                string $message
            ) use (&$errorMessage): bool {
                $errorMessage = $message;

                return true;
            }
        );

        try{
            $process = proc_open(
                $command,
                $descriptors,
                $pipes,
                $workingDirectory
                    ?? $this->rootDirectory
            );
        }catch(Throwable $exception){
            restore_error_handler();

            return [
                'exit_code' => -1,
                'stdout' => '',
                'stderr' => $exception->getMessage(),
            ];
        }

        restore_error_handler();

        if(!is_resource($process)){
            return [
                'exit_code' => -1,
                'stdout' => '',
                'stderr' => $errorMessage
                    ?? '无法启动命令进程。',
            ];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents(
            $pipes[1]
        );

        fclose($pipes[1]);

        $stderr = stream_get_contents(
            $pipes[2]
        );

        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout === false
                ? ''
                : $stdout,
            'stderr' => $stderr === false
                ? ''
                : $stderr,
        ];
    }

    private function readFile(string $file): string
    {
        $content = file_get_contents($file);

        if($content === false){
            throw new RuntimeException(
                '文件读取失败：'
                . $this->relativePath($file)
            );
        }

        return $content;
    }

    private function path(string $relativePath): string
    {
        return $this->rootDirectory
            . DIRECTORY_SEPARATOR
            . str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                $relativePath
            );
    }

    private function relativePath(string $path): string
    {
        $normalizedRoot = str_replace(
            '\\',
            '/',
            $this->rootDirectory
        ) . '/';

        $normalizedPath = str_replace(
            '\\',
            '/',
            $path
        );

        return str_replace(
            $normalizedRoot,
            '',
            $normalizedPath
        );
    }
}