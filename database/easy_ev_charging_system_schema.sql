-- =========================================================
-- 项目名称：电动汽车充电站运营管理系统
-- 数据库名称：easy_ev_charging_system
-- 适用环境：MySQL 8.0 / MariaDB 10.4+
-- 字符编码：utf8mb4
--
-- 说明：
-- 1. 本脚本会删除并重新创建 easy_ev_charging_system 数据库。
-- 2. 数据库内部字段使用规范英文命名，所有字段均附中文注释。
-- 3. 页面、按钮、状态、错误提示等用户可见内容应全部显示中文。
-- =========================================================

SET NAMES utf8mb4;

SET SESSION sql_mode =
    'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP DATABASE IF EXISTS easy_ev_charging_system;

CREATE DATABASE easy_ev_charging_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE easy_ev_charging_system;

CREATE TABLE users (
    user_id INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户主键编号',
    username VARCHAR(30) NOT NULL COMMENT '登录用户名，仅允许3到30位英文字母和数字',
    password_hash VARCHAR(255) NOT NULL COMMENT '密码哈希值，不保存明文密码',
    real_name VARCHAR(20) NOT NULL COMMENT '用户真实姓名，应用层限制为2到20个汉字',
    mobile CHAR(11) NOT NULL COMMENT '中国大陆11位手机号码',
    email VARCHAR(100) NULL COMMENT '电子邮箱，可选填写',
    role VARCHAR(20) NOT NULL DEFAULT 'user' COMMENT '用户角色：admin管理员，user普通用户',
    status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT '账户状态：active正常，disabled已停用',
    last_login_at DATETIME NULL COMMENT '最近一次成功登录时间',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '账户创建时间',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '账户最后更新时间',
    PRIMARY KEY (user_id),
    UNIQUE KEY uk_users_username (username),
    UNIQUE KEY uk_users_mobile (mobile),
    UNIQUE KEY uk_users_email (email),
    KEY idx_users_role (role),
    KEY idx_users_status (status),
    KEY idx_users_created_at (created_at),
    CONSTRAINT chk_users_username_format CHECK (username REGEXP '^[A-Za-z0-9]{3,30}$'),
    CONSTRAINT chk_users_real_name_length CHECK (CHAR_LENGTH(real_name) BETWEEN 2 AND 20),
    CONSTRAINT chk_users_mobile_format CHECK (mobile REGEXP '^1[3-9][0-9]{9}$'),
    CONSTRAINT chk_users_email_format CHECK (
        email IS NULL
        OR email REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}$'
    ),
    CONSTRAINT chk_users_role CHECK (role IN ('admin', 'user')),
    CONSTRAINT chk_users_status CHECK (status IN ('active', 'disabled'))
) ENGINE=InnoDB COMMENT='系统用户表';

CREATE TABLE login_attempts (
    login_attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '登录尝试记录主键编号',
    attempt_scope VARCHAR(20) NOT NULL COMMENT '限制范围：account账户标识，ip客户端IP',
    attempt_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '登录标识或IP地址的SHA-256摘要',
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '当前观察窗口内的失败次数',
    window_started_at DATETIME NOT NULL COMMENT '本轮失败统计窗口开始时间',
    last_failed_at DATETIME NOT NULL COMMENT '最近一次登录失败时间',
    locked_until DATETIME NULL COMMENT '临时限制截止时间，未限制时为空',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '记录最后更新时间',
    PRIMARY KEY (login_attempt_id),
    UNIQUE KEY uk_login_attempts_scope_key (attempt_scope, attempt_key),
    KEY idx_login_attempts_locked_until (locked_until),
    KEY idx_login_attempts_updated_at (updated_at),
    CONSTRAINT chk_login_attempts_scope CHECK (attempt_scope IN ('account', 'ip')),
    CONSTRAINT chk_login_attempts_key_length CHECK (CHAR_LENGTH(attempt_key) = 64),
    CONSTRAINT chk_login_attempts_failed_count CHECK (failed_attempts BETWEEN 1 AND 255),
    CONSTRAINT chk_login_attempts_time_order CHECK (
        locked_until IS NULL OR locked_until >= last_failed_at
    )
) ENGINE=InnoDB COMMENT='登录失败次数和临时限制记录表';

CREATE TABLE locations (
    location_id INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '充电站点主键编号',
    location_code VARCHAR(30) NOT NULL COMMENT '站点业务编号，例如LOC-SH-001',
    location_name VARCHAR(100) NOT NULL COMMENT '充电站点中文名称',
    province VARCHAR(50) NOT NULL COMMENT '省、自治区或直辖市',
    city VARCHAR(50) NOT NULL COMMENT '城市',
    district VARCHAR(50) NOT NULL COMMENT '区或县',
    detailed_address VARCHAR(255) NOT NULL COMMENT '详细地址',
    description VARCHAR(500) NULL COMMENT '站点介绍和补充说明',
    longitude DECIMAL(10,7) NULL COMMENT '经度，可用于地图定位',
    latitude DECIMAL(10,7) NULL COMMENT '纬度，可用于地图定位',
    status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT '站点状态：active运营中，maintenance维护中，inactive已停用',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '站点创建时间',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '站点最后更新时间',
    PRIMARY KEY (location_id),
    UNIQUE KEY uk_locations_code (location_code),
    UNIQUE KEY uk_locations_name_address (location_name, province, city, district, detailed_address),
    KEY idx_locations_name (location_name),
    KEY idx_locations_region (province, city, district),
    KEY idx_locations_status (status),
    KEY idx_locations_status_name_id (status, location_name, location_id),
    KEY idx_locations_created_at (created_at),
    CONSTRAINT chk_locations_code_not_blank CHECK (CHAR_LENGTH(TRIM(location_code)) BETWEEN 3 AND 30),
    CONSTRAINT chk_locations_name_not_blank CHECK (CHAR_LENGTH(TRIM(location_name)) BETWEEN 2 AND 100),
    CONSTRAINT chk_locations_province_not_blank CHECK (CHAR_LENGTH(TRIM(province)) BETWEEN 2 AND 50),
    CONSTRAINT chk_locations_city_not_blank CHECK (CHAR_LENGTH(TRIM(city)) BETWEEN 2 AND 50),
    CONSTRAINT chk_locations_district_not_blank CHECK (CHAR_LENGTH(TRIM(district)) BETWEEN 2 AND 50),
    CONSTRAINT chk_locations_address_not_blank CHECK (CHAR_LENGTH(TRIM(detailed_address)) BETWEEN 2 AND 255),
    CONSTRAINT chk_locations_longitude CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180),
    CONSTRAINT chk_locations_latitude CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
    CONSTRAINT chk_locations_status CHECK (status IN ('active', 'maintenance', 'inactive'))
) ENGINE=InnoDB COMMENT='充电站点信息表';

CREATE TABLE charging_stations (
    station_id INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '充电桩主键编号',
    station_code VARCHAR(40) NOT NULL COMMENT '充电桩业务编号，例如SH-PD-001-A01',
    station_name VARCHAR(100) NOT NULL COMMENT '充电桩中文显示名称',
    location_id INT UNSIGNED NOT NULL COMMENT '所属充电站点编号',
    charger_type VARCHAR(10) NOT NULL COMMENT '充电方式：ac交流充电，dc直流充电',
    power_kw DECIMAL(8,2) UNSIGNED NOT NULL COMMENT '额定充电功率，单位为千瓦',
    hourly_rate DECIMAL(10,2) UNSIGNED NOT NULL COMMENT '每小时充电费用，单位为人民币元',
    status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT '设备状态：active可用，maintenance维护中，inactive已停用',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '充电桩创建时间',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '充电桩最后更新时间',
    PRIMARY KEY (station_id),
    UNIQUE KEY uk_charging_stations_code (station_code),
    UNIQUE KEY uk_charging_stations_name_per_location (location_id, station_name),
    KEY idx_charging_stations_location (location_id),
    KEY idx_charging_stations_status (status),
    KEY idx_charging_stations_status_location_id (status, location_id, station_id),
    KEY idx_charging_stations_type (charger_type),
    KEY idx_charging_stations_power (power_kw),
    KEY idx_charging_stations_rate (hourly_rate),
    CONSTRAINT fk_charging_stations_location FOREIGN KEY (location_id)
        REFERENCES locations(location_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT chk_charging_stations_code_not_blank CHECK (CHAR_LENGTH(TRIM(station_code)) BETWEEN 3 AND 40),
    CONSTRAINT chk_charging_stations_name_not_blank CHECK (CHAR_LENGTH(TRIM(station_name)) BETWEEN 2 AND 100),
    CONSTRAINT chk_charging_stations_type CHECK (charger_type IN ('ac', 'dc')),
    CONSTRAINT chk_charging_stations_power CHECK (power_kw > 0),
    CONSTRAINT chk_charging_stations_rate CHECK (hourly_rate >= 0),
    CONSTRAINT chk_charging_stations_status CHECK (status IN ('active', 'maintenance', 'inactive'))
) ENGINE=InnoDB COMMENT='具体充电桩设备表';

CREATE TABLE charge_records (
    charge_record_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '充电订单主键编号',
    order_number VARCHAR(40) NOT NULL COMMENT '对外展示的充电订单编号',
    user_id INT UNSIGNED NOT NULL COMMENT '使用充电桩的用户编号',
    station_id INT UNSIGNED NOT NULL COMMENT '本次使用的具体充电桩编号',
    check_in_at DATETIME NOT NULL COMMENT '开始充电时间',
    check_out_at DATETIME NULL COMMENT '结束充电时间，充电中时为空',
    hourly_rate_snapshot DECIMAL(10,2) UNSIGNED NOT NULL COMMENT '开始充电时保存的每小时费率快照',
    billable_minutes INT UNSIGNED NULL COMMENT '按照计费规则计算出的计费分钟数',
    total_cost DECIMAL(10,2) UNSIGNED NULL COMMENT '本次充电最终费用，单位为人民币元',
    status VARCHAR(20) NOT NULL DEFAULT 'charging' COMMENT '订单状态：charging充电中，completed已完成，abnormal异常，cancelled已取消',
    remark VARCHAR(500) NULL COMMENT '异常处理、取消原因或其他备注',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '订单创建时间',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '订单最后更新时间',
    active_user_id INT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN status = 'charging' THEN user_id ELSE NULL END
    ) STORED COMMENT '用于限制同一用户只能有一条进行中的订单',
    active_station_id INT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN status = 'charging' THEN station_id ELSE NULL END
    ) STORED COMMENT '用于限制同一充电桩只能有一条进行中的订单',
    PRIMARY KEY (charge_record_id),
    UNIQUE KEY uk_charge_records_order_number (order_number),
    UNIQUE KEY uk_charge_records_one_active_per_user (active_user_id),
    UNIQUE KEY uk_charge_records_one_active_per_station (active_station_id),
    KEY idx_charge_records_user (user_id),
    KEY idx_charge_records_station (station_id),
    KEY idx_charge_records_status (status),
    KEY idx_charge_records_check_in (check_in_at),
    KEY idx_charge_records_check_out (check_out_at),
    KEY idx_charge_records_user_time (user_id, check_in_at),
    KEY idx_charge_records_station_time (station_id, check_in_at),
    KEY idx_charge_records_revenue_time (status, check_out_at, total_cost),
    KEY idx_charge_records_revenue_station (status, station_id, check_out_at, total_cost),
    CONSTRAINT fk_charge_records_user FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_charge_records_station FOREIGN KEY (station_id)
        REFERENCES charging_stations(station_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT chk_charge_records_order_number_not_blank CHECK (CHAR_LENGTH(TRIM(order_number)) BETWEEN 8 AND 40),
    CONSTRAINT chk_charge_records_rate CHECK (hourly_rate_snapshot >= 0),
    CONSTRAINT chk_charge_records_billable_minutes CHECK (billable_minutes IS NULL OR billable_minutes > 0),
    CONSTRAINT chk_charge_records_total_cost CHECK (total_cost IS NULL OR total_cost >= 0),
    CONSTRAINT chk_charge_records_status CHECK (
        status IN ('charging', 'completed', 'abnormal', 'cancelled')
    ),
    CONSTRAINT chk_charge_records_time_order CHECK (
        check_out_at IS NULL OR check_out_at >= check_in_at
    ),
    CONSTRAINT chk_charge_records_charging_data CHECK (
        status <> 'charging'
        OR (
            check_out_at IS NULL
            AND billable_minutes IS NULL
            AND total_cost IS NULL
        )
    ),
    CONSTRAINT chk_charge_records_completed_data CHECK (
        status <> 'completed'
        OR (
            check_out_at IS NOT NULL
            AND billable_minutes IS NOT NULL
            AND billable_minutes > 0
            AND total_cost IS NOT NULL
        )
    ),
    CONSTRAINT chk_charge_records_non_normal_data CHECK (
        status NOT IN ('abnormal', 'cancelled')
        OR (
            check_out_at IS NOT NULL
            AND remark IS NOT NULL
            AND CHAR_LENGTH(TRIM(remark)) > 0
        )
    )
) ENGINE=InnoDB COMMENT='用户充电订单及历史记录表';

CREATE TABLE location_reviews (
    location_review_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '站点评价主键编号',
    user_id INT UNSIGNED NOT NULL COMMENT '发表评价的用户编号',
    location_id INT UNSIGNED NOT NULL COMMENT '被评价的充电站点编号',
    charge_record_id BIGINT UNSIGNED NOT NULL COMMENT '用于证明用户曾在该站点充电的订单编号',
    rating TINYINT UNSIGNED NOT NULL COMMENT '用户评分，1到5颗星',
    content VARCHAR(1000) NOT NULL COMMENT '用户评论内容',
    admin_reply VARCHAR(1000) NULL COMMENT '管理员回复内容',
    reply_admin_user_id INT UNSIGNED NULL COMMENT '回复该评价的管理员用户编号',
    replied_at DATETIME NULL COMMENT '管理员回复时间',
    status VARCHAR(20) NOT NULL DEFAULT 'visible' COMMENT '评价状态：visible公开显示，hidden管理员隐藏',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '评价创建时间',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '评价最后更新时间',

    PRIMARY KEY (location_review_id),

    UNIQUE KEY uk_location_reviews_user_location (user_id, location_id),

    KEY idx_location_reviews_user (user_id),
    KEY idx_location_reviews_location (location_id),
    KEY idx_location_reviews_record (charge_record_id),
    KEY idx_location_reviews_location_status_time (location_id, status, created_at),
    KEY idx_location_reviews_rating (rating),
    KEY idx_location_reviews_reply_admin (reply_admin_user_id),
    KEY idx_location_reviews_status (status),

    CONSTRAINT fk_location_reviews_user FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_location_reviews_location FOREIGN KEY (location_id)
        REFERENCES locations(location_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_location_reviews_record FOREIGN KEY (charge_record_id)
        REFERENCES charge_records(charge_record_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_location_reviews_reply_admin FOREIGN KEY (reply_admin_user_id)
        REFERENCES users(user_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT chk_location_reviews_rating CHECK (rating BETWEEN 1 AND 5),

    CONSTRAINT chk_location_reviews_content_not_blank CHECK (
        CHAR_LENGTH(TRIM(content)) BETWEEN 1 AND 1000
    ),

    CONSTRAINT chk_location_reviews_admin_reply_length CHECK (
        admin_reply IS NULL
        OR CHAR_LENGTH(TRIM(admin_reply)) BETWEEN 1 AND 1000
    ),

    CONSTRAINT chk_location_reviews_status CHECK (
        status IN ('visible', 'hidden')
    ),

    CONSTRAINT chk_location_reviews_reply_data CHECK (
        (
            admin_reply IS NULL
            AND reply_admin_user_id IS NULL
            AND replied_at IS NULL
        )
        OR
        (
            admin_reply IS NOT NULL
            AND reply_admin_user_id IS NOT NULL
            AND replied_at IS NOT NULL
        )
    )
) ENGINE=InnoDB COMMENT='用户对充电站点的公开评价表';

CREATE TABLE admin_operation_logs (
    admin_operation_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '管理员操作日志主键编号',
    operator_user_id INT UNSIGNED NOT NULL COMMENT '执行操作的管理员用户编号',
    action VARCHAR(60) NOT NULL COMMENT '操作类型，例如user_status_update、location_update、station_status_update',
    target_type VARCHAR(60) NOT NULL COMMENT '操作对象类型，例如user、location、station、charge_record',
    target_id BIGINT UNSIGNED NULL COMMENT '操作对象编号',
    result VARCHAR(20) NOT NULL DEFAULT 'success' COMMENT '操作结果：success成功，failure失败',
    detail VARCHAR(1000) NULL COMMENT '操作详情，记录关键变化或失败原因',
    ip_address VARCHAR(45) NULL COMMENT '操作者IP地址，兼容IPv4和IPv6',
    user_agent VARCHAR(255) NULL COMMENT '操作者浏览器或客户端信息',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '操作发生时间',
    PRIMARY KEY (admin_operation_log_id),
    KEY idx_admin_operation_logs_operator_time (operator_user_id, created_at),
    KEY idx_admin_operation_logs_target (target_type, target_id),
    KEY idx_admin_operation_logs_action (action),
    KEY idx_admin_operation_logs_result (result),
    KEY idx_admin_operation_logs_created_at (created_at),
    CONSTRAINT fk_admin_operation_logs_operator FOREIGN KEY (operator_user_id)
        REFERENCES users(user_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT chk_admin_operation_logs_action_not_blank CHECK (CHAR_LENGTH(TRIM(action)) BETWEEN 2 AND 60),
    CONSTRAINT chk_admin_operation_logs_target_type_not_blank CHECK (CHAR_LENGTH(TRIM(target_type)) BETWEEN 2 AND 60),
    CONSTRAINT chk_admin_operation_logs_result CHECK (result IN ('success', 'failure'))
) ENGINE=InnoDB COMMENT='管理员操作审计日志表';

SHOW TABLES;
SHOW FULL COLUMNS FROM users;
SHOW FULL COLUMNS FROM locations;
SHOW FULL COLUMNS FROM charging_stations;
SHOW FULL COLUMNS FROM charge_records;
SHOW FULL COLUMNS FROM location_reviews;
SHOW FULL COLUMNS FROM login_attempts;
SHOW FULL COLUMNS FROM admin_operation_logs;