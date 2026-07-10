-- =========================================================
-- 项目名称：电动汽车充电站运营管理系统
-- 数据库名称：easy_ev_charging_system
-- 文件用途：演示数据
--
-- 使用方式：
-- 1. 先执行 database/easy_ev_charging_system_schema.sql
-- 2. 再执行本文件
--
-- 说明：
-- 1. 本文件只用于本地演示和项目展示。
-- 2. 本文件不负责建库建表。
-- 3. 本文件假定数据库已经由 schema 文件重建完成。
-- 4. 演示账户密码统一为：Aa123456!
-- =========================================================

SET NAMES utf8mb4;

USE easy_ev_charging_system;

INSERT INTO users (
    user_id,
    username,
    password_hash,
    real_name,
    mobile,
    email,
    role,
    status,
    last_login_at,
    created_at,
    updated_at
) VALUES
(
    1,
    'admin001',
    '$2y$12$byiJhOuMtfMOFIm.sG0wpOxVIscftsWLNbOndWZKVhuXRD9kwQVpi',
    '系统管理员',
    '13800000000',
    'admin001@example.com',
    'admin',
    'active',
    NULL,
    '2026-06-01 09:00:00',
    '2026-06-01 09:00:00'
),
(
    2,
    'user001',
    '$2y$12$byiJhOuMtfMOFIm.sG0wpOxVIscftsWLNbOndWZKVhuXRD9kwQVpi',
    '张三',
    '13800000001',
    'user001@example.com',
    'user',
    'active',
    NULL,
    '2026-06-02 10:00:00',
    '2026-06-02 10:00:00'
),
(
    3,
    'user002',
    '$2y$12$byiJhOuMtfMOFIm.sG0wpOxVIscftsWLNbOndWZKVhuXRD9kwQVpi',
    '李四',
    '13800000002',
    'user002@example.com',
    'user',
    'active',
    NULL,
    '2026-06-03 10:00:00',
    '2026-06-03 10:00:00'
),
(
    4,
    'user003',
    '$2y$12$byiJhOuMtfMOFIm.sG0wpOxVIscftsWLNbOndWZKVhuXRD9kwQVpi',
    '王五',
    '13800000003',
    'user003@example.com',
    'user',
    'disabled',
    NULL,
    '2026-06-04 10:00:00',
    '2026-06-04 10:00:00'
);

INSERT INTO locations (
    location_id,
    location_code,
    location_name,
    province,
    city,
    district,
    detailed_address,
    description,
    longitude,
    latitude,
    status,
    created_at,
    updated_at
) VALUES
(
    1,
    'LOC-SH-PD-001',
    '上海浦东张江充电中心',
    '上海市',
    '上海市',
    '浦东新区',
    '张江高科技园区科苑路88号',
    '靠近办公园区和住宅区，适合日常通勤补能。',
    121.5999600,
    31.2042100,
    'active',
    '2026-06-05 09:00:00',
    '2026-06-05 09:00:00'
),
(
    2,
    'LOC-BJ-HD-001',
    '北京海淀中关村充电站',
    '北京市',
    '北京市',
    '海淀区',
    '中关村大街15号地下停车场B区',
    '站点正在维护，部分设备暂不对外开放。',
    116.3162000,
    39.9839600,
    'maintenance',
    '2026-06-06 09:00:00',
    '2026-06-06 09:00:00'
),
(
    3,
    'LOC-GZ-TH-001',
    '广州天河体育中心充电站',
    '广东省',
    '广州市',
    '天河区',
    '天河路299号停车场东区',
    '历史站点，当前已停用，仅保留历史订单展示。',
    113.3237000,
    23.1376100,
    'inactive',
    '2026-06-07 09:00:00',
    '2026-06-07 09:00:00'
);

INSERT INTO charging_stations (
    station_id,
    station_code,
    station_name,
    location_id,
    charger_type,
    power_kw,
    hourly_rate,
    status,
    created_at,
    updated_at
) VALUES
(
    1,
    'SH-PD-001-A01',
    '张江A区1号直流快充桩',
    1,
    'dc',
    120.00,
    18.00,
    'active',
    '2026-06-05 10:00:00',
    '2026-06-05 10:00:00'
),
(
    2,
    'SH-PD-001-A02',
    '张江A区2号交流慢充桩',
    1,
    'ac',
    7.00,
    6.00,
    'active',
    '2026-06-05 10:05:00',
    '2026-06-05 10:05:00'
),
(
    3,
    'BJ-HD-001-B01',
    '中关村B区1号直流快充桩',
    2,
    'dc',
    90.00,
    15.00,
    'maintenance',
    '2026-06-06 10:00:00',
    '2026-06-06 10:00:00'
),
(
    4,
    'BJ-HD-001-B02',
    '中关村B区2号交流慢充桩',
    2,
    'ac',
    7.00,
    5.00,
    'maintenance',
    '2026-06-06 10:05:00',
    '2026-06-06 10:05:00'
),
(
    5,
    'GZ-TH-001-C01',
    '天河C区1号直流快充桩',
    3,
    'dc',
    100.00,
    16.00,
    'inactive',
    '2026-06-07 10:00:00',
    '2026-06-07 10:00:00'
);

INSERT INTO charge_records (
    charge_record_id,
    order_number,
    user_id,
    station_id,
    check_in_at,
    check_out_at,
    hourly_rate_snapshot,
    billable_minutes,
    total_cost,
    status,
    remark,
    created_at,
    updated_at
) VALUES
(
    1,
    'EC202606300001',
    2,
    1,
    '2026-06-30 09:00:00',
    NULL,
    18.00,
    NULL,
    NULL,
    'charging',
    NULL,
    '2026-06-30 09:00:00',
    '2026-06-30 09:00:00'
),
(
    2,
    'EC202606200001',
    2,
    2,
    '2026-06-20 10:00:00',
    '2026-06-20 11:25:00',
    6.00,
    85,
    8.50,
    'completed',
    NULL,
    '2026-06-20 10:00:00',
    '2026-06-20 11:25:00'
),
(
    3,
    'EC202606210001',
    3,
    2,
    '2026-06-21 14:00:00',
    '2026-06-21 15:10:00',
    6.00,
    70,
    7.00,
    'completed',
    NULL,
    '2026-06-21 14:00:00',
    '2026-06-21 15:10:00'
),
(
    4,
    'EC202606220001',
    3,
    3,
    '2026-06-22 09:30:00',
    '2026-06-22 10:45:00',
    15.00,
    75,
    18.75,
    'completed',
    NULL,
    '2026-06-22 09:30:00',
    '2026-06-22 10:45:00'
),
(
    5,
    'EC202606240001',
    2,
    3,
    '2026-06-24 18:00:00',
    '2026-06-24 18:35:00',
    15.00,
    35,
    8.75,
    'abnormal',
    '设备通信异常，管理员结束订单。',
    '2026-06-24 18:00:00',
    '2026-06-24 18:35:00'
),
(
    6,
    'EC202606250001',
    2,
    2,
    '2026-06-25 12:00:00',
    '2026-06-25 12:05:00',
    6.00,
    NULL,
    NULL,
    'cancelled',
    '用户临时取消本次充电。',
    '2026-06-25 12:00:00',
    '2026-06-25 12:05:00'
);

INSERT INTO location_reviews (
    location_review_id,
    user_id,
    location_id,
    charge_record_id,
    rating,
    content,
    admin_reply,
    reply_admin_user_id,
    replied_at,
    status,
    created_at,
    updated_at
) VALUES
(
    1,
    2,
    1,
    2,
    5,
    '站点位置方便，快充速度很稳定，停车场指引也比较清楚。',
    '感谢您的反馈，我们会继续保持设备稳定运行。',
    1,
    '2026-06-23 09:30:00',
    'visible',
    '2026-06-20 12:00:00',
    '2026-06-23 09:30:00'
),
(
    2,
    3,
    1,
    3,
    4,
    '整体体验不错，交流慢充适合长时间停车时使用。',
    NULL,
    NULL,
    NULL,
    'visible',
    '2026-06-21 16:00:00',
    '2026-06-21 16:00:00'
),
(
    3,
    3,
    2,
    4,
    3,
    '维护期间可用设备较少，希望后续能提前提示维护时间。',
    '您好，该站点维护信息后续会在站点详情中提前展示。',
    1,
    '2026-06-23 10:00:00',
    'hidden',
    '2026-06-22 11:30:00',
    '2026-06-23 10:00:00'
);

INSERT INTO admin_operation_logs (
    admin_operation_log_id,
    operator_user_id,
    action,
    target_type,
    target_id,
    result,
    detail,
    ip_address,
    user_agent,
    created_at
) VALUES
(
    1,
    1,
    'admin_login_success',
    'user',
    1,
    'success',
    '管理员登录成功。',
    '127.0.0.1',
    'Demo Browser',
    '2026-06-20 09:00:00'
),
(
    2,
    1,
    'location_create',
    'location',
    1,
    'success',
    '新增充电站点成功：LOC-SH-PD-001 / 上海浦东张江充电中心。',
    '127.0.0.1',
    'Demo Browser',
    '2026-06-20 09:10:00'
),
(
    3,
    1,
    'station_status_update',
    'station',
    3,
    'success',
    '充电桩状态修改成功：中关村B区1号直流快充桩 -> 维护中。',
    '127.0.0.1',
    'Demo Browser',
    '2026-06-22 09:00:00'
),
(
    4,
    1,
    'charge_record_abnormal_finish',
    'charge_record',
    5,
    'success',
    '异常结束订单成功：EC202606240001，原因：设备通信异常。',
    '127.0.0.1',
    'Demo Browser',
    '2026-06-24 18:35:00'
),
(
    5,
    1,
    'location_review_reply',
    'location_review',
    1,
    'success',
    '管理员回复站点评价成功。',
    '127.0.0.1',
    'Demo Browser',
    '2026-06-23 09:30:00'
),
(
    6,
    1,
    'location_review_status_update',
    'location_review',
    3,
    'success',
    '管理员隐藏站点评价成功。',
    '127.0.0.1',
    'Demo Browser',
    '2026-06-23 10:00:00'
);