@use('App\Icons\Ios')
@use('App\Icons\Android')

<native:bottom-nav>
    <native:bottom-nav-item id="alerts" label="Alerts" url="/" icon="notifications" :ios-icon="Ios::Bell" :android-icon="Android::Notifications" :active="$active === 'alerts'"></native:bottom-nav-item>
    <native:bottom-nav-item id="rules" label="Rules" url="/rules" icon="list" :ios-icon="Ios::ListBullet" :android-icon="Android::List" :active="$active === 'rules'"></native:bottom-nav-item>
    <native:bottom-nav-item id="locations" label="Locations" url="/locations" icon="place" :ios-icon="Ios::Mappin" :android-icon="Android::Place" :active="$active === 'locations'"></native:bottom-nav-item>
    <native:bottom-nav-item id="account" label="Account" url="/account" icon="person" :ios-icon="Ios::Person" :android-icon="Android::Person" :active="$active === 'account'"></native:bottom-nav-item>
</native:bottom-nav>
