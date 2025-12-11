<?php

namespace App\Enums;

enum ActivityType: string
{
    case CHECK_IN = 'check_in';
    case CHECK_OUT = 'check_out';
    case TASK_CREATED = 'task_created';
    case TASK_UPDATED = 'task_updated';
    case ATTENDANCE_EDITED = 'attendance_edited';
    case ATTENDANCE_DELETED = 'attendance_deleted';
    case USER_CREATED = 'user_created';
    case USER_UPDATED = 'user_updated';
    case USER_DELETED = 'user_deleted';
    case LEAVE_REQUESTED = 'leave_requested';
    case LEAVE_APPROVED = 'leave_approved';
    case LEAVE_REJECTED = 'leave_rejected';
    case HOLIDAY_CREATED = 'holiday_created';
    case HOLIDAY_UPDATED = 'holiday_updated';
    case HOLIDAY_DELETED = 'holiday_deleted';
    case AUTO_CHECKOUT = 'auto_checkout';
    case LOGIN = 'login';
    case LOGOUT = 'logout';
    case PASSWORD_CHANGED = 'password_changed';
    case WHATSAPP_CONNECTED = 'whatsapp_connected';
    case WHATSAPP_DISCONNECTED = 'whatsapp_disconnected';
    case WHATSAPP_RELINKED = 'whatsapp_relinked';
    case WHATSAPP_SETTINGS_UPDATED = 'whatsapp_settings_updated';
    case WHATSAPP_RECAP_SENT = 'whatsapp_recap_sent';
    case WHATSAPP_TEST_SENT = 'whatsapp_test_sent';
}

