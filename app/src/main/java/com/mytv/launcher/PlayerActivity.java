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

public class PlayerActivity extends AppCompatActivity {
    private ExoPlayer player;
    private PlayerView playerView;
    private TextView statusLabel;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        String streamUrl = getIntent().getStringExtra("url");
        String title = getIntent().getStringExtra("title");

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Color.BLACK);

        playerView = new PlayerView(this);
        playerView.setLayoutParams(new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT));
        playerView.setUseController(true);
        playerView.setControllerShowTimeoutMs(3500);
        playerView.setKeepScreenOn(true);
        root.addView(playerView);

        TextView titleBar = new TextView(this);
        titleBar.setText(title != null ? title : "CONNECT TV");
        titleBar.setTextColor(Color.WHITE);
        titleBar.setTextSize(20);
        titleBar.setPadding(40, 25, 40, 20);
        titleBar.setBackgroundColor(0x88000000);
        FrameLayout.LayoutParams tp = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.TOP);
        root.addView(titleBar, tp);
        titleBar.postDelayed(() -> titleBar.setVisibility(View.GONE), 4000);

        statusLabel = new TextView(this);
        statusLabel.setTextColor(0xFF38BDF8);
        statusLabel.setTextSize(18);
        statusLabel.setGravity(Gravity.CENTER);
        statusLabel.setVisibility(View.GONE);
        root.addView(statusLabel, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER));

        setContentView(root);

        if (streamUrl == null || streamUrl.isEmpty()) {
            finish();
            return;
        }

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

        player.setMediaItem(MediaItem.fromUri(streamUrl));
        player.prepare();
        player.play();
    }

    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
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
        if (player != null) {
            player.release();
            player = null;
        }
    }
}
