package com.mytv.launcher;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.webkit.JavascriptInterface;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import org.json.JSONObject;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.Scanner;

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

        webView.setWebViewClient(new WebViewClient());
        webView.addJavascriptInterface(new MyBridge(this, webView), "MyTV");
        webView.loadUrl("file:///android_asset/index.html");
    }

    @Override
    public void onBackPressed() {
        // منع الخروج بالرجوع
    }

    public static class MyBridge {
        private Context context;
        private WebView webView;

        public MyBridge(Context context, WebView webView) {
            this.context = context;
            this.webView = webView;
        }

        @JavascriptInterface
        public void launch(String packageName) {
            try {
                Intent intent = context.getPackageManager().getLaunchIntentForPackage(packageName);
                if (intent != null) {
                    intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                    context.startActivity(intent);
                }
            } catch (Exception ignored) {}
        }

        // دالة الأندرويد الأصلية للاتصال بالإنترنت بدون حظر CORS
        @JavascriptInterface
        public void fetchUrl(String targetUrl) {
            new Thread(() -> {
                try {
                    URL url = new URL(targetUrl);
                    HttpURLConnection conn = (HttpURLConnection) url.openConnection();
                    conn.setRequestMethod("GET");
                    conn.setConnectTimeout(10000);
                    conn.setReadTimeout(10000);

                    int code = conn.getResponseCode();
                    if (code >= 200 && code < 300) {
                        InputStream in = conn.getInputStream();
                        Scanner s = new Scanner(in, "UTF-8").useDelimiter("\\A");
                        String body = s.hasNext() ? s.next() : "{}";
                        in.close();

                        String safeJson = JSONObject.quote(body);
                        webView.post(() -> webView.evaluateJavascript("window.onCloudData(true, " + safeJson + ");", null));
                    } else {
                        webView.post(() -> webView.evaluateJavascript("window.onCloudData(false, 'HTTP " + code + "');", null));
                    }
                } catch (Exception e) {
                    String err = JSONObject.quote(e.getMessage() != null ? e.getMessage() : "Connection failed");
                    webView.post(() -> webView.evaluateJavascript("window.onCloudData(false, " + err + ");", null));
                }
            }).start();
        }
    }
}
