import { useEffect, useState } from 'react';
import { View, Text, Pressable, ScrollView, Platform } from 'react-native';
import DateTimePicker from '@react-native-community/datetimepicker';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../src/components/Banner';
import { Button } from '../src/components/Button';
import { ErrorState, LoadingState } from '../src/components/ErrorState';
import { colors } from '../src/constants/colors';
import { useReminderPreferences } from '../src/hooks/useReminderPreferences';
import { OfflineNotice } from '../src/components/OfflineNotice';
import { ErrorNotice } from '../src/components/ErrorNotice';
import { formatWallClock } from '../src/lib/datetime';

const DAYS: { iso: number; label: string }[] = [
  { iso: 1, label: 'Mon' },
  { iso: 2, label: 'Tue' },
  { iso: 3, label: 'Wed' },
  { iso: 4, label: 'Thu' },
  { iso: 5, label: 'Fri' },
  { iso: 6, label: 'Sat' },
  { iso: 7, label: 'Sun' },
];

export default function ReminderSettings() {
  const { data, loading, error, isOffline, reload, save } = useReminderPreferences();
  const [enabled, setEnabled] = useState(true);
  const [days, setDays] = useState<number[] | null>(null);
  const [time, setTime] = useState<string | null>(null);
  const [showPicker, setShowPicker] = useState(false);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  useEffect(() => {
    if (data) {
      setEnabled(data.reminder_enabled);
      setDays(data.reminder_days);
      setTime(data.reminder_time);
    }
  }, [data]);

  if (loading && !data) return <LoadingState />;
  if (error && !data) return <ErrorState message={error.message} onRetry={reload} />;
  if (!data) return null;

  const effectiveDays = days ?? data.defaults.days;
  const effectiveTime = time ?? data.defaults.time;

  function toggleDay(iso: number) {
    const current = days ?? data!.defaults.days;
    const next = current.includes(iso) ? current.filter((d) => d !== iso) : [...current, iso].sort();
    // An empty selection means "follow my batch" (null), not "never remind
    // me" — reminder_enabled is what turns reminders off.
    setDays(next.length === 0 ? null : next);
  }

  async function onSave() {
    setSaveError(null);
    setSaving(true);
    const result = await save({ reminder_enabled: enabled, reminder_days: days, reminder_time: time });
    setSaving(false);
    if (!result.ok) setSaveError(result.error);
  }

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={{ paddingBottom: 40 }}>
      <View style={{ paddingTop: 50, paddingHorizontal: 20, flexDirection: 'row', alignItems: 'center', gap: 12 }}>
        <Pressable onPress={() => router.back()}>
          <Ionicons name="chevron-back" size={22} color={colors.black} />
        </Pressable>
        <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black }}>Reminder Settings</Text>
      </View>

      <OfflineNotice feature="reminderSettings" show={isOffline} />

      <Banner variant="info">
        We'll nudge you if a working day's journal entry is still missing. Turn this off entirely, or customize
        which days and what time.
      </Banner>

      {/* Two different mechanisms sit behind one setting, and they behave
          differently — saying so is more useful than implying one system. */}
      <Banner variant="neutral">
        These days and times also set an alarm on this phone, so you still get reminded with no internet. The
        on-phone reminder is a general nudge — it can't check which entries are missing without a connection.
      </Banner>

      {saveError ? <ErrorNotice message={saveError} /> : null}

      <Pressable
        onPress={() => setEnabled((e) => !e)}
        style={{
          marginHorizontal: 20,
          marginTop: 16,
          flexDirection: 'row',
          alignItems: 'center',
          justifyContent: 'space-between',
          backgroundColor: colors.white,
          borderWidth: 1.5,
          borderColor: colors.gray200,
          borderRadius: 12,
          padding: 16,
        }}
      >
        <Text style={{ fontSize: 14, fontWeight: '600', color: colors.black }}>Reminders Enabled</Text>
        <Ionicons name={enabled ? 'toggle' : 'toggle-outline'} size={28} color={enabled ? colors.blue600 : colors.gray400} />
      </Pressable>

      <View style={{ marginHorizontal: 20, marginTop: 16 }}>
        <Text style={{ fontSize: 10, fontWeight: '600', color: colors.gray600, marginBottom: 8, textTransform: 'uppercase' }}>
          Days {days === null ? '(following your batch)' : ''}
        </Text>
        <View style={{ flexDirection: 'row', gap: 8, flexWrap: 'wrap' }}>
          {DAYS.map((d) => {
            const active = effectiveDays.includes(d.iso);
            return (
              <Pressable
                key={d.iso}
                onPress={() => toggleDay(d.iso)}
                disabled={!enabled}
                style={{
                  width: 44,
                  height: 44,
                  borderRadius: 22,
                  alignItems: 'center',
                  justifyContent: 'center',
                  backgroundColor: active ? colors.blue600 : colors.white,
                  borderWidth: 1.5,
                  borderColor: active ? colors.blue600 : colors.gray200,
                  opacity: enabled ? 1 : 0.5,
                }}
              >
                <Text style={{ fontSize: 11, fontWeight: '600', color: active ? 'white' : colors.gray600 }}>{d.label}</Text>
              </Pressable>
            );
          })}
        </View>
      </View>

      <View style={{ marginHorizontal: 20, marginTop: 16 }}>
        <Text style={{ fontSize: 10, fontWeight: '600', color: colors.gray600, marginBottom: 8, textTransform: 'uppercase' }}>
          Time {time === null ? `(batch default ${formatWallClock(data.defaults.time)})` : ''}
        </Text>
        <Pressable
          onPress={() => setShowPicker(true)}
          disabled={!enabled}
          style={{
            borderWidth: 1.5,
            borderColor: colors.gray200,
            borderRadius: 10,
            padding: 12,
            backgroundColor: colors.white,
            opacity: enabled ? 1 : 0.5,
          }}
        >
          {/* Shown as standard time. What gets STORED is still 24-hour
              "HH:mm" — the backend's reminder_time is a time column and the
              reminder command reads the hour off it, so only the display
              changes here. */}
          <Text style={{ fontSize: 13.5, color: colors.black }}>{formatWallClock(effectiveTime)}</Text>
        </Pressable>
        {showPicker ? (
          <DateTimePicker
            value={new Date(`1970-01-01T${effectiveTime}:00`)}
            mode="time"
            // The picker itself now offers AM/PM rather than a 24-hour dial.
            is24Hour={false}
            onChange={(_, selected) => {
              setShowPicker(Platform.OS === 'ios');
              if (selected) {
                // getHours() is 24-hour regardless of how the picker was
                // displayed, so the stored value is unchanged in shape.
                const hh = String(selected.getHours()).padStart(2, '0');
                const mm = String(selected.getMinutes()).padStart(2, '0');
                setTime(`${hh}:${mm}`);
              }
            }}
          />
        ) : null}
      </View>

      <Button label="Save" icon="checkmark" loading={saving} disabled={saving} onPress={onSave} style={{ marginHorizontal: 20, marginTop: 20 }} />
    </ScrollView>
  );
}
