package com.tgbetalert.app;

import android.Manifest;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Bundle;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.EditText;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;

public class MainActivity extends AppCompatActivity {
    private static final int NOTIFY_CODE = 21;
    private EditText urlInput;
    private EditText pinInput;
    private TextView statusText;
    private WebView webView;
    private boolean pinInjected;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);
        urlInput = findViewById(R.id.urlInput);
        pinInput = findViewById(R.id.pinInput);
        statusText = findViewById(R.id.statusText);
        webView = findViewById(R.id.web);
        Button startBtn = findViewById(R.id.startBtn);

        urlInput.setText(Prefs.baseUrl(this));
        pinInput.setText(Prefs.pin(this));
        setupWeb();
        loadSite();
        if (Prefs.alertsOn(this)) {
            startAlerts();
        }

        startBtn.setOnClickListener(v -> {
            String url = urlInput.getText().toString().trim();
            String pin = pinInput.getText().toString().trim();
            if (!url.startsWith("http://") && !url.startsWith("https://")) {
                Toast.makeText(this, "Server link https se start honi chahiye", Toast.LENGTH_LONG).show();
                return;
            }
            if (pin.isEmpty()) {
                Toast.makeText(this, "PIN likho", Toast.LENGTH_SHORT).show();
                return;
            }
            Prefs.save(this, url, pin);
            pinInjected = false;
            loadSite();
            startAlerts();
        });
    }

    private void setupWeb() {
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        webView.setWebViewClient(new WebViewClient() {
            @Override
            public void onPageFinished(WebView view, String url) {
                if (pinInjected) return;
                pinInjected = true;
                String pin = Prefs.pin(MainActivity.this).replace("'", "");
                view.evaluateJavascript("localStorage.setItem('tga_pin','" + pin + "'); location.reload();", null);
            }
        });
    }

    private void loadSite() {
        String url = Prefs.baseUrl(this);
        if (url.startsWith("http")) {
            webView.loadUrl(url + "/");
        }
    }

    private void startAlerts() {
        if (Build.VERSION.SDK_INT >= 33 && ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            ActivityCompat.requestPermissions(this, new String[]{Manifest.permission.POST_NOTIFICATIONS}, NOTIFY_CODE);
            statusText.setText("Notification Allow karo, phir dubara Save dabao.");
            return;
        }
        startForegroundService(new Intent(this, AlertService.class));
        statusText.setText("Alerts on. App band karke bhi notification aayegi.");
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == NOTIFY_CODE && grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
            startAlerts();
        }
    }

    @Override
    public void onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack();
            return;
        }
        super.onBackPressed();
    }
}
