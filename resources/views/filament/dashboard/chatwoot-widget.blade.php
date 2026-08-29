<script>
  window.chatwootSettings = {
    identifierHash: @json($identityHash),
  };

  (function (d, t) {
    var BASE_URL = @json(config('services.chatwoot.base_url'));
    var g = d.createElement(t), s = d.getElementsByTagName(t)[0];
    g.src = BASE_URL + '/packs/js/sdk.js';
    g.defer = true;
    g.async = true;
    s.parentNode.insertBefore(g, s);
    g.onload = function () {
      window.chatwootSDK.run({
        websiteToken: @json(config('services.chatwoot.website_token')),
        baseUrl: BASE_URL,
      });

      window.addEventListener('chatwoot:ready', function () {
        window.$chatwoot.setUser(@json((string) auth()->id()), {
          identifier_hash: @json($identityHash),
          email: @json(auth()->user()->email),
          name: @json(auth()->user()->name),
        });
      });
    };
  })(document, 'script');
</script>
