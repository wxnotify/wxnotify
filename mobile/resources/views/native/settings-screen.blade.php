<native:top-bar title="Settings" display-mode="large"></native:top-bar>

@include('native.partials.tabs', ['active' => 'settings'])

{{-- Un-prefixed on purpose: native:validate's element list does not know list or list-item. --}}
<list separator class="w-full h-full bg-theme-background">
    <list-item headline="Alert rules" supporting="What you are alerted about, and where" @tap="openRules"></list-item>
    <list-item headline="Locations" supporting="The places and phones you watch" @tap="openLocations"></list-item>
    <list-item headline="Account" supporting="Your plan, push, location sharing and sign out" @tap="openAccount"></list-item>
</list>
