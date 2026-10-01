package com.tgbetalert.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (!Prefs.alertsOn(context)) return;
        Intent service = new Intent(context, AlertService.class);
        context.startForegroundService(service);
    }
}
