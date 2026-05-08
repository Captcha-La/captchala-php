<?php
declare(strict_types=1);

namespace Captchala\Cms;

/**
 * Renders the CaptchaLa widget markup that every CMS plugin embeds into
 * its target form.
 *
 * Output shape:
 *
 *   <div id="captchala-<uniq>" data-captchala
 *        data-app-key="..." data-server-token="sct_..." data-action="login"
 *        data-product="bind"
 *        [data-bind-to="..."]
 *        [data-lang="..."]></div>
 *   <input type="hidden" name="captchala_token" value="">      (always emitted)
 *   <script src="https://cdn.captcha-cdn.net/captchala-loader.js" defer></script>
 *   <script>(function(){ ...boot reads data-* and calls window.Captchala.init({...})... })();</script>
 *
 * Default mode is "bind": the widget intercepts the form's submit button,
 * runs the challenge, writes pt_token to a hidden input, and lets the form
 * submit. The bootstrap auto-detects the submit element of the closest
 * <form> ancestor when no explicit selector is provided — this is what
 * makes the plugin "drop-in" with no theme tweaks.
 *
 * Options:
 *   product       string   popup | float | embed | bind     (default: bind)
 *   lang          string   BCP-47 tag, e.g. ja, pt-BR, or 'auto'
 *   bind_to       string   CSS selector of the submit button (only honoured
 *                          for product=bind/popup; default = auto-detect
 *                          inside the closest form)
 *   loader_url    string   override CDN URL (test / on-prem)
 *   uniq          string   force a specific element id suffix (test only)
 *   hidden_input  bool     legacy opt; the hidden <input name="captchala_token">
 *                          is always emitted, this flag is now a no-op
 *                          (kept for backward compatibility)
 */
final class Widget
{
    public const LOADER_URL = 'https://cdn.captcha-cdn.net/captchala-loader.js';

    /**
     * @param array<string,mixed> $opts
     */
    public static function renderHtml(
        string $appKey,
        string $serverToken,
        string $action,
        array $opts = []
    ): string {
        if (!Action::isValid($action)) {
            throw new \InvalidArgumentException(
                sprintf('Unknown CaptchaLa action: %s', $action)
            );
        }

        $product   = !empty($opts['product'])     ? (string)$opts['product']     : 'bind';
        $lang      = !empty($opts['lang'])        ? (string)$opts['lang']        : '';
        $theme     = !empty($opts['theme'])       ? (string)$opts['theme']       : '';
        $bindTo    = !empty($opts['bind_to'])     ? (string)$opts['bind_to']     : '';
        $uniq      = !empty($opts['uniq'])        ? (string)$opts['uniq']
                                                  : substr(bin2hex(random_bytes(6)), 0, 12);
        $elemId    = 'captchala-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $uniq);

        // Curated brand-safe presets. Each maps to a custom-theme block with
        // a single main colour — the SDK derives gradient + hover + ring from
        // it. Kept in sync with SettingsPage::curated_preset_color().
        $curatedPresets = [
            'slate'   => '#475569',
            'emerald' => '#10b981',
            'amber'   => '#f59e0b',
            'rose'    => '#f43f5e',
        ];

        // Map our settings into the SDK theme config:
        //   default | dark | stereoscopic   → preset string passed through
        //   slate / emerald / amber / rose  → JSON {custom:{color}}
        //   custom + fields                 → JSON {custom:{color,gradient,hover,brightness,radius}}
        // Stored in data-theme as either a bare preset string or a JSON blob;
        // the bootstrap parses accordingly.
        $themeAttr = '';
        if (isset($curatedPresets[$theme])) {
            $themeAttr = json_encode(
                ['custom' => ['color' => $curatedPresets[$theme], 'brightness' => 'system']],
                JSON_UNESCAPED_SLASHES
            );
        } elseif ($theme === 'custom') {
            $custom = [];
            if (!empty($opts['theme_color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$opts['theme_color'])) {
                $custom['color'] = strtolower((string)$opts['theme_color']);
            }
            if (!empty($opts['theme_gradient'])) $custom['gradient'] = (string)$opts['theme_gradient'];
            if (!empty($opts['theme_hover']))    $custom['hover']    = (string)$opts['theme_hover'];
            if (!empty($opts['theme_radius']))   $custom['radius']   = (string)$opts['theme_radius'];
            $brightness = !empty($opts['theme_brightness']) ? (string)$opts['theme_brightness'] : 'system';
            if (in_array($brightness, ['light', 'dark', 'system'], true)) {
                $custom['brightness'] = $brightness;
            }
            if ($custom !== []) {
                $themeAttr = json_encode(['custom' => $custom], JSON_UNESCAPED_SLASHES);
            }
        } elseif (in_array($theme, ['dark', 'stereoscopic'], true)) {
            $themeAttr = $theme;
        } elseif ($theme === 'default' || $theme === '') {
            $themeAttr = '';   // leave blank → SDK uses its own default
        }

        $attrs = [
            'id'                => $elemId,
            'data-captchala'    => '',
            'data-app-key'      => $appKey,
            'data-server-token' => $serverToken,
            'data-action'       => $action,
            'data-product'      => $product,
            // Default spacing so the widget never butts against the submit
            // button below it. Users can override via theme CSS targeting
            // [data-captchala] { margin: ...; }.
            'style'             => 'margin: 0 0 16px;',
        ];
        if ($lang !== '')      $attrs['data-lang']    = $lang;
        if ($themeAttr !== '') $attrs['data-theme']   = $themeAttr;
        if ($bindTo !== '')    $attrs['data-bind-to'] = $bindTo;

        // Optional: boot script will hit this URL with action=<refresh_action>
        // and action_name=<refresh_scene> when the SDK reports an expired
        // token, swap the new sct_ in, and re-init the widget.
        if (!empty($opts['refresh_url']) && !empty($opts['refresh_action']) && !empty($opts['refresh_scene'])) {
            $attrs['data-refresh-url']    = (string)$opts['refresh_url'];
            $attrs['data-refresh-action'] = (string)$opts['refresh_action'];
            $attrs['data-refresh-scene']  = (string)$opts['refresh_scene'];
        }

        $div = '<div';
        foreach ($attrs as $k => $v) {
            $div .= $v === ''
                ? ' ' . $k
                : ' ' . $k . '="' . self::esc($v) . '"';
        }
        $div .= '></div>';

        // Hidden input always emitted: bind / popup mode doesn't render a
        // visible widget chrome, and the only way the resolved pt_token rides
        // back to the server is via this input.
        $hidden = '<input type="hidden" name="captchala_token" value="">';

        $loader = !empty($opts['loader_url']) ? (string)$opts['loader_url'] : self::LOADER_URL;
        $loaderScript = '<script src="' . self::esc($loader) . '" defer></script>';

        // Inline bootstrap. Reads data-* off the div, waits for window.loadCaptchala,
        // and configures the widget per product mode.
        //
        //   bind    — intercept form submit; auto-detect submit button in closest <form>
        //   popup   — same as bind (button-bound trigger)
        //   float / embed — render visible widget inline (appendTo the div)
        //
        // CAREFUL: this script gets embedded inside WordPress comment_form output,
        // which goes through HTML normalization that turns `&&` into `&#038;&#038;`
        // and breaks the JS. We avoid `&&` by using ternaries / nested ifs, and
        // avoid `<` by using `!==` length checks instead of `i<n` loops.
        $boot = '<script>(function(){'
            . 'var id=' . json_encode($elemId, JSON_UNESCAPED_SLASHES) . ';'
            . 'function findSubmit(form){'
            .     'if(!form){return null;}'
            .     'var sels=["button[type=submit]","input[type=submit]","button:not([type])","button[name=wp-submit]","#submit","#wp-submit","#submitcomment"];'
            .     'for(var i=0;i!==sels.length;i++){var b=form.querySelector(sels[i]); if(b){return b;}}'
            .     'return null;'
            . '}'
            . 'function ready(){'
            .     'var el=document.getElementById(id);'
            .     'if(!el){return;}'
            .     'if(!window.loadCaptchala){return setTimeout(ready,50);}'
            .     'if(el.getAttribute("data-captchala-bound")==="1"){return;}'
            .     'el.setAttribute("data-captchala-bound","1");'
            .     'window.loadCaptchala(function(){'
            .         'if(!window.Captchala){console.error("[captchala] window.Captchala missing"); return;}'
            .         'if(!window.Captchala.init){console.error("[captchala] window.Captchala.init missing"); return;}'
            .         'var product=el.getAttribute("data-product")||"bind";'
            .         'var cfg={'
            .             'appKey:el.getAttribute("data-app-key"),'
            .             'serverToken:el.getAttribute("data-server-token"),'
            .             'action:el.getAttribute("data-action"),'
            .             'product:product'
            .         '};'
            .         'var lang=el.getAttribute("data-lang"); if(lang){cfg.lang=lang;}'
            .         'var t=el.getAttribute("data-theme");'
            .         'if(t){if(t.charAt(0)==="{"){try{cfg.theme=JSON.parse(t);}catch(e){cfg.theme=t;}}else{cfg.theme=t;}}'
            .         'var form=el.closest?el.closest("form"):null;'
            .         'var hidden=form?form.querySelector(\'input[name="captchala_token"]\'):null;'
            .         'if(!hidden){if(form){'
            .             'hidden=document.createElement("input");'
            .             'hidden.type="hidden"; hidden.name="captchala_token";'
            .             'form.appendChild(hidden);'
            .         '}}'
            .         'var inst=window.Captchala.init(cfg).onSuccess(function(res){'
            .             'if(hidden){hidden.value=(res?(res.token||""):"");}'
            .             'el.dispatchEvent(new CustomEvent("captchala:success",{bubbles:true,detail:res}));'
            .             'if(form){if(product==="bind"){setTimeout(function(){'
            .                 'if(form.dataset.captchalaSubmitted==="1"){return;}'
            .                 'form.dataset.captchalaSubmitted="1";'
            .                 'try{HTMLFormElement.prototype.submit.call(form);}catch(e){console.error("[captchala] auto-submit failed",e);}'
            .             '},120);}}'
            .         '}).onError(function(err){'
            .             'var raw=typeof err==="string"?err:(err?(err.message||String(err)):"");'
            .             'var mm=raw.match(/\\(([a-z_]+)\\)\\s*$/);'
            .             'var code=mm?mm[1]:(err?(err.code||err.error||""):"");'
            .             'var refreshable=["server_token_required","server_token_invalid","server_token_exhausted","server_token_app_mismatch","server_token_binding_mismatch","token_expired","server_token_expired","challenge_expired","session_expired"];'
            .             'var refreshUrl=el.getAttribute("data-refresh-url");'
            .             'var refreshAction=el.getAttribute("data-refresh-action");'
            .             'var refreshScene=el.getAttribute("data-refresh-scene");'
            .             'if(refreshable.indexOf(code)!==-1){if(refreshUrl){if(refreshAction){if(refreshScene){'
            .                 'var fd=new FormData();'
            .                 'fd.append("action",refreshAction);'
            .                 'fd.append("action_name",refreshScene);'
            .                 'fetch(refreshUrl,{method:"POST",credentials:"same-origin",body:fd})'
            .                     '.then(function(r){return r.json();})'
            .                     '.then(function(j){'
            .                         'var t=(j?(j.data?j.data.server_token:""):"")||"";'
            .                         'if(!t){return;}'
            .                         'try{if(inst.destroy){inst.destroy();}}catch(e){}'
            .                         'el.setAttribute("data-server-token",t);'
            .                         'el.removeAttribute("data-captchala-bound");'
            .                         'ready();'
            .                     '}).catch(function(e){console.error("[captchala] refresh failed",e);});'
            .                 'return;'
            .             '}}}}'
            .             'console.error("[captchala] challenge error",err);'
            .             'el.dispatchEvent(new CustomEvent("captchala:error",{bubbles:true,detail:err}));'
            .         '});'
            .         'var bindSel=el.getAttribute("data-bind-to");'
            .         'if(product==="bind"){'
            .             'if(bindSel){inst.bindTo(bindSel);}'
            .             'else{var btn=findSubmit(form); if(btn){inst.bindTo(btn);} else{inst.appendTo("#"+id);}}'
            .         '}else{'
            .             'inst.appendTo("#"+id);'
            .         '}'
            .         'function neuter(node){'
            .             'if(!node){return;}'
            .             'var bs=node.querySelectorAll?node.querySelectorAll("button:not([type])"):[];'
            .             'for(var i=0;i!==bs.length;i++){bs[i].type="button";}'
            .         '}'
            .         'neuter(el);'
            .         'if(typeof MutationObserver!=="undefined"){'
            .             'try{new MutationObserver(function(){neuter(el);}).observe(el,{childList:true,subtree:true});}catch(e){}'
            .         '}'
            .     '},function(loaderErr){'
            .         'console.error("[captchala] loader error",loaderErr);'
            .     '});'
            . '}'
            . 'if(document.readyState==="loading"){'
            .     'document.addEventListener("DOMContentLoaded",ready);'
            . '}else{ready();}'
            . '})();</script>';

        return $div . $hidden . $loaderScript . $boot;
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function __construct() {}
}
