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
import androidx.core.content.FileProvider;
import org.json.JSONObject;
import java.io.File;
import java.io.FileOutputStream;
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
        if (webView != null) {
            webView.evaluateJavascript("if(window.onNativeResume) window.onNativeResume();", null);
        }
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (webView != null) {
            webView.evaluateJavascript("if(window.onNativePause) window.onNativePause();", null);
        }
    }

    @Override
    public void onBackPressed() {
        if (webView != null) {
            webView.evaluateJavascript("window.appBack ? window.appBack() : false", value -> {});
        }
    }

    public static class NativeBridge {
        private Context context;
        private WebView webView;
        private SharedPreferences prefs;
        private volatile boolean dlBusy = false;

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
        public boolean canInstall() {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                return context.getPackageManager().canRequestPackageInstalls();
            }
            return true;
        }

        @JavascriptInterface
        public void openInstallPermission() {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                Intent intent = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES);
                intent.setData(Uri.parse("package:" + context.getPackageName()));
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                context.startActivity(intent);
            }
        }

        @JavascriptInterface
        public void openSystem(String type) {
            try {
                Intent intent;
                if ("wifi".equalsIgnoreCase(type)) intent = new Intent(Settings.ACTION_WIFI_SETTINGS);
                else if ("apps".equalsIgnoreCase(type)) intent = new Intent(Settings.ACTION_APPLICATION_SETTINGS);
                else intent = new Intent(Settings.ACTION_SETTINGS);
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

        private void notifyDl(String tag, String state, int pct) {
            webView.post(() -> webView.evaluateJavascript("if(window.__dl) window.__dl(" + JSONObject.quote(tag) + ", '" + state + "', " + pct + ");", null));
        }

        @JavascriptInterface
        public void download(String tag, String apkUrl) {
            if (dlBusy) {
                notifyDl(tag, "busy", -1);
                return;
            }
            dlBusy = true;
            new Thread(() -> {
                try {
                    notifyDl(tag, "progress", 0);
                    String currentUrl = apkUrl;
                    HttpURLConnection conn = null;
                    for (int r = 0; r < 6; r++) {
                        URL u = new URL(currentUrl);
                        conn = (HttpURLConnection) u.openConnection();
                        conn.setInstanceFollowRedirects(false);
                        conn.setConnectTimeout(15000);
                        conn.setReadTimeout(30000);
                        conn.connect();
                        int code = conn.getResponseCode();
                        if (code == 301 || code == 302 || code == 303 || code == 307 || code == 308) {
                            String loc = conn.getHeaderField("Location");
                            conn.disconnect();
                            if (loc == null) break;
                            currentUrl = new URL(u, loc).toExternalForm();
                        } else break;
                    }

                    if (conn == null || conn.getResponseCode() < 200 || conn.getResponseCode() >= 300) {
                        notifyDl(tag, "error", -1);
                        dlBusy = false;
                        return;
                    }

                    int total = conn.getContentLength();
                    File dir = new File(context.getCacheDir(), "apks");
                    if (!dir.exists()) dir.mkdirs();
                    File apkFile = new File(dir, "app_" + tag.replaceAll("[^a-zA-Z0-9_]", "") + ".apk");
                    if (apkFile.exists()) apkFile.delete();

                    try (InputStream in = conn.getInputStream(); FileOutputStream out = new FileOutputStream(apkFile)) {
                        byte[] buf = new byte[8192];
                        int n;
                        long got = 0;
                        int lastPct = 0;
                        while ((n = in.read(buf)) != -1) {
                            out.write(buf, 0, n);
                            got += n;
                            if (total > 0) {
                                int pct = (int)((got * 100L) / total);
                                if (pct != lastPct) {
                                    lastPct = pct;
                                    notifyDl(tag, "progress", pct);
                                }
                            }
                        }
                        out.flush();
                    }

                    notifyDl(tag, "install", 100);
                    dlBusy = false;

                    Uri uri = FileProvider.getUriForFile(context, context.getPackageName() + ".provider", apkFile);
                    Intent intent = new Intent(Intent.ACTION_VIEW);
                    intent.setDataAndType(uri, "application/vnd.android.package-archive");
                    intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
                    context.startActivity(intent);
                } catch (Exception e) {
                    dlBusy = false;
                    notifyDl(tag, "error", -1);
                }
            }).start();
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
