package com.mytv.launcher;

import android.graphics.Color;
import android.os.Bundle;
import android.view.Gravity;
import android.view.KeyEvent;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.TextView;
import androidx.appcompat.app.AppCompatActivity;
import androidx.media3.common.MediaItem;
import androidx.media3.common.PlaybackException;
import androidx.media3.common.Player;
import androidx.media3.datasource.DefaultHttpDataSource;
import androidx.media3.exoplayer.ExoPlayer;
import androidx.media3.exoplayer.source.DefaultMediaSourceFactory;
import androidx.media3.ui.PlayerView;
import java.util.ArrayList;

public class PlayerActivity extends AppCompatActivity {
    private ExoPlayer player;
    private PlayerView playerView;
    private TextView statusLabel, titleBar;
    private ArrayList<String> urls = new ArrayList<>();
    private ArrayList<String> titles = new ArrayList<>();
    private int curIdx = 0;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        ArrayList<String> uList = getIntent().getStringArrayListExtra("urls");
        ArrayList<String> tList = getIntent().getStringArrayListExtra("titles");
        if (uList != null && !uList.isEmpty()) {
            urls = uList;
            titles = (tList != null && tList.size() == uList.size()) ? tList : uList;
            curIdx = Math.max(0, Math.min(getIntent().getIntExtra("index", 0), urls.size() - 1));
        } else {
            String u = getIntent().getStringExtra("url");
            String t = getIntent().getStringExtra("title");
            if (u != null && !u.isEmpty()) {
                urls.add(u);
                titles.add(t != null ? t : "CONNECT TV");
            }
        }

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Color.BLACK);

        playerView = new PlayerView(this);
        playerView.setLayoutParams(new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        playerView.setUseController(true);
        playerView.setControllerShowTimeoutMs(3500);
        playerView.setKeepScreenOn(true);
        root.addView(playerView);

        titleBar = new TextView(this);
        titleBar.setTextColor(Color.WHITE);
        titleBar.setTextSize(20);
        titleBar.setPadding(40, 25, 40, 20);
        titleBar.setBackgroundColor(0x99000000);
        root.addView(titleBar, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.TOP));

        statusLabel = new TextView(this);
        statusLabel.setTextColor(0xFF38BDF8);
        statusLabel.setTextSize(18);
        statusLabel.setGravity(Gravity.CENTER);
        statusLabel.setVisibility(View.GONE);
        root.addView(statusLabel, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER));

        setContentView(root);

        if (urls.isEmpty()) { finish(); return; }

        DefaultHttpDataSource.Factory dsFactory = new DefaultHttpDataSource.Factory()
                .setUserAgent("IPTVSmartersPlayer")
                .setAllowCrossProtocolRedirects(true)
                .setConnectTimeoutMs(15000)
                .setReadTimeoutMs(15000);

        player = new ExoPlayer.Builder(this)
                .setMediaSourceFactory(new DefaultMediaSourceFactory(this).setDataSourceFactory(dsFactory))
                .build();
        playerView.setPlayer(player);

        player.addListener(new Player.Listener() {
            @Override
            public void onPlaybackStateChanged(int state) {
                if (state == Player.STATE_BUFFERING) {
                    statusLabel.setText("⏳ جاري تشغيل البث...");
                    statusLabel.setVisibility(View.VISIBLE);
                } else {
                    statusLabel.setVisibility(View.GONE);
                }
            }
            @Override
            public void onPlayerError(PlaybackException error) {
                statusLabel.setText("❌ تعذر تشغيل القناة حالياً");
                statusLabel.setVisibility(View.VISIBLE);
            }
        });

        playAt(curIdx);
    }

    private void playAt(int idx) {
        if (urls.isEmpty()) return;
        curIdx = (idx + urls.size()) % urls.size();
        titleBar.setText(titles.get(curIdx));
        titleBar.setVisibility(View.VISIBLE);
        titleBar.removeCallbacks(hideTitle);
        titleBar.postDelayed(hideTitle, 4000);
        statusLabel.setVisibility(View.GONE);
        player.setMediaItem(MediaItem.fromUri(urls.get(curIdx)));
        player.prepare();
        player.play();
    }

    private final Runnable hideTitle = () -> titleBar.setVisibility(View.GONE);

    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_DPAD_UP || keyCode == KeyEvent.KEYCODE_CHANNEL_UP) {
            playAt(curIdx - 1); return true;
        }
        if (keyCode == KeyEvent.KEYCODE_DPAD_DOWN || keyCode == KeyEvent.KEYCODE_CHANNEL_DOWN) {
            playAt(curIdx + 1); return true;
        }
        if (keyCode == KeyEvent.KEYCODE_DPAD_CENTER || keyCode == KeyEvent.KEYCODE_ENTER) {
            if (player != null) {
                if (player.isPlaying()) player.pause(); else player.play();
                playerView.showController();
                return true;
            }
        }
        return super.onKeyDown(keyCode, event);
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        if (player != null) { player.release(); player = null; }
    }
}
