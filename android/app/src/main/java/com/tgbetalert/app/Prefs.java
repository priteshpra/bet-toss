package com.tgbetalert.app;

import android.content.Context;
import android.content.SharedPreferences;

import java.util.HashSet;
import java.util.Set;

public final class Prefs {
    private static final String NAME = "tgbet";
    public static final String DEFAULT_URL = "https://bet-toss.onrender.com";
    public static final String DEFAULT_PIN = "2565";

    private Prefs() {}

    public static SharedPreferences of(Context context) {
        return context.getSharedPreferences(NAME, Context.MODE_PRIVATE);
    }

    public static String baseUrl(Context context) {
        String saved = of(context).getString("baseUrl", DEFAULT_URL);
        if (saved == null || saved.isEmpty() || saved.contains("trycloudflare.com")) {
            return DEFAULT_URL;
        }
        return saved;
    }

    public static String pin(Context context) {
        String saved = of(context).getString("pin", DEFAULT_PIN);
        if (saved == null || saved.isEmpty() || "9362".equals(saved)) {
            return DEFAULT_PIN;
        }
        return saved;
    }

    public static boolean alertsOn(Context context) {
        return of(context).getBoolean("alertsOn", false);
    }

    public static void save(Context context, String url, String pin) {
        of(context).edit()
                .putString("baseUrl", trimUrl(url))
                .putString("pin", pin == null ? "" : pin.trim())
                .putBoolean("alertsOn", true)
                .apply();
    }

    public static String trimUrl(String url) {
        if (url == null) return "";
        String value = url.trim();
        while (value.endsWith("/")) {
            value = value.substring(0, value.length() - 1);
        }
        return value;
    }

    public static Set<String> seen(Context context) {
        return new HashSet<>(of(context).getStringSet("seen", new HashSet<>()));
    }

    public static void saveSeen(Context context, Set<String> seen, boolean primed) {
        of(context).edit()
                .putStringSet("seen", seen)
                .putBoolean("primed", primed)
                .apply();
    }

    public static boolean primed(Context context) {
        return of(context).getBoolean("primed", false);
    }
}
