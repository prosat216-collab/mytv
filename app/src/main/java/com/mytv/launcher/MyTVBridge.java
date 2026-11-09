package com.mytv.launcher;

import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import android.os.Build;
import android.provider.Settings;
import android.webkit.JavascriptInterface;
import android.webkit.WebView;
import org.json.JSONObject;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.Locale;
import java.util.TimeZone;

public class MyTVBridge {
    private Context context;
    private WebView webView;
    private SharedPreferences prefs;

    public MyTVBridge(Context context, WebView webView) {
        this.context = context;
        this.webView = webView;
        this.prefs = context.getSharedPreferences("mytv_prefs", Context.MODE_PRIVATE);
    }

    @JavascriptInterface
    public String deviceInfo() {
        JSONObject json = new JSONObject();
        try {
            String uid = Settings.Secure.getString(context.getContentResolver(), Settings.Secure.ANDROID_ID);
            json.put("uid", uid != null ? uid : "tv-box");
            json.put("model", Build.MANUFACTURER + " " + Build.MODEL);
            json.put("type", "tv");
            json.put("ver", "1.0");
            json.put("code", 1);
            json.put("lang", Locale.getDefault().getLanguage());
            json.put("tz", TimeZone.getDefault().getID());
        } catch (Exception ignored) {}
        return json.toString();
    }

    @JavascriptInterface
    public String prefGet(String key) {
        return prefs.getString(key, "");
    }

    @JavascriptInterface
    public void prefSet(String key, String value) {
        prefs.edit().putString(key, value).apply();
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
    public void openUrl(String url) {
        try {
            Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
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
                conn.setConnectTimeout(15000);
                conn.setDoOutput(true);

                conn.getOutputStream().write(jsonBody.getBytes("UTF-8"));
                ok = conn.getResponseCode() >= 200 && conn.getResponseCode() < 300;
                InputStream in = ok ? conn.getInputStream() : conn.getErrorStream();
                if (in != null) {
                    java.util.Scanner s = new java.util.Scanner(in).useDelimiter("\\A");
                    resp = s.hasNext() ? s.next() : "{}";
                }
            } catch (Exception ignored) {}
            final boolean finalOk = ok;
            final String finalResp = JSONObject.quote(resp);
            webView.post(() -> webView.evaluateJavascript("window.__api('" + reqId + "', " + finalOk + ", " + finalResp + ");", null));
        }).start();
    }
