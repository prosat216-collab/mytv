package com.mytv.launcher;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.webkit.JavascriptInterface;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

public class MainActivity extends Activity {
    private WebView webView;
    private static final String SHOP_APP_URL = "https://azsatconnect.alwaysdata.net/app.html";

    @Override
    @SuppressLint("SetJavaScript
