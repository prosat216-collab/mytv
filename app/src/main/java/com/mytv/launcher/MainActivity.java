package com.mytv.launcher;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.webkit.JavascriptInterface;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import org.json.JSONObject;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.Locale;
import java.util.Scanner;
import java.util.TimeZone;

public class MainActivity extends Activity {
    private WebView webView;

    @Override
    @SuppressLint("SetJavaScriptEnabled")
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        webView = new WebView(this);
        setContentView(webView);

        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setAllowFileAccess(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setAllowFileAccessFromFileURLs(true);
        s.setAllowUniversalAccessFromFileURLs(true);

        webView.setWebViewClient(new WebViewClient());
        webView.addJavascriptInterface(new NativeBridge(this, webView), "MyTV");
        webView.loadUrl("file:///android_asset/index.html");
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (webView != null) webView.evaluateJavascript("if(window.onNativeResume) window.onNativeResume();", null);
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (webView != null) webView.evaluateJavascript("if(window.onNativePause) window.onNativePause();", null);
    }

    @Override
    public void onBackPressed() {
        if (webView != null) webView.evaluateJavascript("window.appBack ? window.appBack() : false", value -> {});
    }

    public static class NativeBridge {
        private Context context;
        private WebView webView;
        private SharedPreferences prefs;

        public NativeBridge(Context context, WebView webView) {
            this.context = context;
            this.webView = webView;
            this.prefs = context.getSharedPreferences("mytv_prefs", Context.MODE_PRIVATE);
        }

        @JavascriptInterface
        public String deviceInfo() {
            JSONObject json = new JSONObject();
            try {
                String uid = Settings.Secure.getString(context.getContentResolver(), Settings.Secure.ANDROID_ID);
                if (uid == null || uid.isEmpty()) uid = "tv-" + Build.SERIAL;
                json.put("uid", uid);
                json.put("model", Build.MANUFACTURER + " " + Build.MODEL);
                json.put("type", "tv");
                json.put("ver", "1.8");
                json.put("code", 18);
                json.put("lang", Locale.getDefault().getLanguage());
                json.put("tz", TimeZone.getDefault().getID());
                json.put("country", Locale.getDefault().getCountry());
            } catch (Exception ignored) {}
            return json.toString();
        }

        @JavascriptInterface
        public String prefGet(String key) { return prefs.getString(key, ""); }

        @JavascriptInterface
        public void prefSet(String key, String value) { prefs.edit().putString(key, value).apply(); }

        @JavascriptInterface
        public int installedVersion(String packageName) {
            try {
                return context.getPackageManager().getPackageInfo(packageName, 0).versionCode;
            } catch (Exception e) { return -1; }
        }

        @JavascriptInterface
        public boolean launch(String packageName) {
            try {
                Intent intent = context.getPackageManager().getLaunchIntentForPackage(packageName);
                if (intent != null) {
                    intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                    context.startActivity(intent);
                    return true;
                }
            } catch (Exception ignored) {}
            return false;
        }

        @JavascriptInterface
        public boolean canInstall() { return true; }

        @JavascriptInterface
        public void openInstallPermission() {}

        @JavascriptInterface
        public void openSystem(String type) {
            try {
                Intent intent = new Intent(Settings.ACTION_SETTINGS);
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                context.startActivity(intent);
            } catch (Exception ignored) {}
        }

        @JavascriptInterface
        public void openUrl(String url) {
            try {
                Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                context.startActivity(intent);
            } catch (Exception ignored) {}
        }

        @JavascriptInterface
        public void download(String tag, String apkUrl) {
            // فتح رابط التحميل مباشرة في المتصفح بكل سهولة وأمان
            try {
                Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(apkUrl));
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                context.startActivity(intent);
            } catch (Exception ignored) {}
        }

        @JavascriptInterface
        public void api(String reqId, String targetUrl, String jsonBody) {
            new Thread(() -> {
                boolean ok = false;
                String resp = "{}";
                try {
                    URL u = new URL(targetUrl);
                    HttpURLConnection conn = (HttpURLConnection) u.openConnection();
                    conn.setRequestMethod("POST");
                    conn.setRequestProperty("Content-Type", "application/json");
                    conn.setRequestProperty("Accept", "application/json");
                    conn.setConnectTimeout(15000);
                    conn.setReadTimeout(15000);
                    conn.setDoOutput(true);

                    if (jsonBody != null && !jsonBody.isEmpty()) {
                        try (OutputStream os = conn.getOutputStream()) {
                            os.write(jsonBody.getBytes("UTF-8"));
                        }
                    }

                    int code = conn.getResponseCode();
                    ok = (code >= 200 && code < 300);
                    InputStream in = ok ? conn.getInputStream() : conn.getErrorStream();
                    if (in != null) {
                        try (Scanner s = new Scanner(in, "UTF-8").useDelimiter("\\A")) {
                            resp = s.hasNext() ? s.next() : "{}";
                        }
                    }
                } catch (Exception ignored) {}

                final boolean finalOk = ok;
                final String finalResp = JSONObject.quote(resp);
                webView.post(() -> webView.evaluateJavascript("window.__api('" + reqId + "', " + finalOk + ", " + finalResp + ");", null));
            }).start();
        }
    }
}
