<?php

namespace App\Enums;

enum ActivityAlertType: string
{
    case AccountCreated = 'account_created';
    case SignedIn = 'signed_in';
    case SignedOut = 'signed_out';
    case NameChanged = 'name_changed';
    case PasswordChanged = 'password_changed';
    case EmailChanged = 'email_changed';
    case MfaChanged = 'mfa_changed';
    case AccountDeleted = 'account_deleted';
    case AlertCreated = 'alert_created';
    case AlertUpdated = 'alert_updated';
    case AlertDeleted = 'alert_deleted';
    case ContactCreated = 'contact_created';
    case ContactUpdated = 'contact_updated';
    case ContactDeleted = 'contact_deleted';
    case LocationCreated = 'location_created';
    case LocationUpdated = 'location_updated';
    case LocationDeleted = 'location_deleted';
    case ScheduleCreated = 'schedule_created';
    case ScheduleUpdated = 'schedule_updated';
    case ScheduleDeleted = 'schedule_deleted';
    case ProductListUpdated = 'product_list_updated';
    case SettingsChanged = 'settings_changed';
    case DailyForecastEnabled = 'daily_forecast_enabled';

    public function label(): string
    {
        return match ($this) {
            self::AccountCreated => 'Account created',
            self::SignedIn => 'Signed in',
            self::SignedOut => 'Signed out',
            self::NameChanged => 'Name changed',
            self::PasswordChanged => 'Password changed',
            self::EmailChanged => 'Email changed',
            self::MfaChanged => 'Multi-factor authentication changed',
            self::AccountDeleted => 'Account deleted',
            self::AlertCreated => 'Alert created',
            self::AlertUpdated => 'Alert updated',
            self::AlertDeleted => 'Alert deleted',
            self::ContactCreated => 'Contact created',
            self::ContactUpdated => 'Contact updated',
            self::ContactDeleted => 'Contact deleted',
            self::LocationCreated => 'Location created',
            self::LocationUpdated => 'Location updated',
            self::LocationDeleted => 'Location deleted',
            self::ScheduleCreated => 'Schedule created',
            self::ScheduleUpdated => 'Schedule updated',
            self::ScheduleDeleted => 'Schedule deleted',
            self::ProductListUpdated => 'Product list updated',
            self::SettingsChanged => 'Settings changed',
            self::DailyForecastEnabled => 'Daily forecast enabled',
        };
    }
}
