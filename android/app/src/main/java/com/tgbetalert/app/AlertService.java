package com.tgbetalert.app;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;

import androidx.core.app.NotificationCompat;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.HashSet;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public class AlertService extends Service {
    public static final String ACTION_POLL = "poll";
    private static final int STATUS_ID = 1;
    private static final long INTERVAL_MS = 12000;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final ExecutorService pool = Executors.newSingleThreadExecutor();
    private boolean polling = false;

    private final Runnable tick = new Runnable() {
        @Override
        public void run() {
            pollOnce();
            handler.postDelayed(this, INTERVAL_MS);
        }
    };

    @Override
    public void onCreate() {
        super.onCreate();
        createChannels();
        Notification status = statusNotification("Alerts on. Naya bet dhundh rahe hain.");
        if (Build.VERSION.SDK_INT >= 29) {
            startForeground(STATUS_ID, status, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC);
        } else {
            startForeground(STATUS_ID, status);
        }
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        handler.removeCallbacks(tick);
        handler.post(tick);
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        handler.removeCallbacks(tick);
        pool.shutdownNow();
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    private void pollOnce() {
        if (polling) return;
        polling = true;
        final String base = Prefs.baseUrl(this);
        final String pin = Prefs.pin(this);
        pool.execute(() -> {
            try {
                JSONObject data = fetch(base, pin);
                handleFeed(data);
            } catch (Exception e) {
                updateStatus("Server wait: " + e.getMessage());
            } finally {
                polling = false;
            }
        });
    }

    private JSONObject fetch(String base, String pin) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(base + "/api.php?action=feed").openConnection();
        conn.setConnectTimeout(20000);
        conn.setReadTimeout(70000);
        conn.setRequestProperty("X-Pin", pin);
        conn.setRequestProperty("Accept", "application/json");
        int code = conn.getResponseCode();
        InputStream stream = code >= 400 ? conn.getErrorStream() : conn.getInputStream();
        String body = readAll(stream);
        conn.disconnect();
        if (code == 401) {
            throw new Exception("PIN galat hai");
        }
        if (code < 200 || code >= 300) {
            throw new Exception("HTTP " + code);
        }
        return new JSONObject(body);
    }

    private void handleFeed(JSONObject data) {
        JSONArray watched = data.optJSONArray("watched");
        if (watched == null) watched = new JSONArray();
        Set<String> seen = Prefs.seen(this);
        boolean primed = Prefs.primed(this);
        int fresh = 0;
        for (int i = watched.length() - 1; i >= 0; i--) {
            JSONObject bet = watched.optJSONObject(i);
            if (bet == null) continue;
            String id = bet.optString("postId", "");
            if (id.isEmpty() || seen.contains(id)) continue;
            seen.add(id);
            if (primed) {
                fresh++;
                showBet(bet, id);
            }
        }
        if (seen.size() > 400) {
            Set<String> trimmed = new HashSet<>();
            int keep = 0;
            for (String id : seen) {
                if (keep++ >= 400) break;
                trimmed.add(id);
            }
            seen = trimmed;
        }
        Prefs.saveSeen(this, seen, true);
        String clock = data.optString("fetchedAt", "");
        updateStatus(fresh > 0 ? (fresh + " naya bet") : ("Live " + clock));
    }

    private void showBet(JSONObject bet, String id) {
        String user = bet.optString("userName", "Watched user");
        String team = bet.optString("teamName", "—");
        String amount = bet.optString("amount", "—");
        Notification notification = new NotificationCompat.Builder(this, "bets")
                .setSmallIcon(android.R.drawable.stat_notify_chat)
                .setContentTitle(user + " ka naya bet")
                .setContentText("Team: " + team + "  |  Amount: " + amount)
                .setStyle(new NotificationCompat.BigTextStyle().bigText("Team: " + team + "  |  Amount: " + amount))
                .setPriority(NotificationCompat.PRIORITY_MAX)
                .setCategory(NotificationCompat.CATEGORY_MESSAGE)
                .setAutoCancel(true)
                .setDefaults(NotificationCompat.DEFAULT_ALL)
                .build();
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) {
            manager.notify(Math.abs(id.hashCode()), notification);
        }
    }

    private void updateStatus(String text) {
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) {
            manager.notify(STATUS_ID, statusNotification(text));
        }
    }

    private Notification statusNotification(String text) {
        return new NotificationCompat.Builder(this, "status")
                .setSmallIcon(android.R.drawable.stat_notify_chat)
                .setContentTitle("TG Bet Alert")
                .setContentText(text)
                .setOngoing(true)
                .setOnlyAlertOnce(true)
                .setPriority(NotificationCompat.PRIORITY_LOW)
                .build();
    }

    private void createChannels() {
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager == null) return;
        NotificationChannel bets = new NotificationChannel("bets", "Bet alerts", NotificationManager.IMPORTANCE_HIGH);
        bets.enableVibration(true);
        bets.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        NotificationChannel status = new NotificationChannel("status", "Alert service", NotificationManager.IMPORTANCE_LOW);
        manager.createNotificationChannel(bets);
        manager.createNotificationChannel(status);
    }

    private static String readAll(InputStream stream) throws Exception {
        if (stream == null) return "";
        BufferedReader reader = new BufferedReader(new InputStreamReader(stream, StandardCharsets.UTF_8));
        StringBuilder out = new StringBuilder();
        String line;
        while ((line = reader.readLine()) != null) {
            out.append(line);
        }
        reader.close();
        return out.toString();
    }
}
