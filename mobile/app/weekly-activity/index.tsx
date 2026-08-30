import { useState } from 'react';
import { ScrollView, View, Text, TextInput, Pressable, Alert } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../../src/components/Banner';
import { Button } from '../../src/components/Button';
import { Card } from '../../src/components/Card';
import { ErrorState, LoadingState } from '../../src/components/ErrorState';
import { OfflineNotice } from '../../src/components/OfflineNotice';
import { useWeeklyActivityLogs } from '../../src/hooks/useWeeklyActivityLogs';
import { dateRangeLabel, todayISO } from '../../src/lib/datetime';
import { colors } from '../../src/constants/colors';

/**
 * Weekly Activity Log and Time Log Summary — the list of Period-Covered
 * sheets. Mirrors web's StudentWeeklyTimeLogPage, which is one sheet per
 * period, each holding N activity rows.
 */
export default function WeeklyActivityIndex() {
  const { logs, loading, error, isOffline, reload, createSheet } = useWeeklyActivityLogs();

  const [showForm, setShowForm] = useState(false);
  const [weekStart, setWeekStart] = useState(todayISO());
  const [weekEnd, setWeekEnd] = useState(todayISO());
  const [area, setArea] = useState('');
  const [creating, setCreating] = useState(false);

  async function onCreate() {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(weekStart) || !/^\d{4}-\d{2}-\d{2}$/.test(weekEnd)) {
      Alert.alert('Check the dates', 'Both dates need to be in YYYY-MM-DD form, e.g. 2026-09-01.');
      return;
    }
    if (weekEnd < weekStart) {
      Alert.alert('Check the dates', 'The end date cannot be before the start date.');
      return;
    }

    setCreating(true);
    const result = await createSheet({
      week_start: weekStart,
      week_end: weekEnd,
      area_assigned: area.trim() || null,
    });
    setCreating(false);

    if (result.ok) {
      setShowForm(false);
      setArea('');
      router.push(`/weekly-activity/${result.id}`);
    } else {
      Alert.alert('Could not create the sheet', result.error);
    }
  }

  if (loading && logs.length === 0) return <LoadingState />;
  if (error && logs.length === 0) return <ErrorState message={error.message} onRetry={reload} />;

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={{ paddingBottom: 32 }}>
      <View
        style={{
          paddingTop: 50,
          paddingBottom: 12,
          paddingHorizontal: 20,
          flexDirection: 'row',
          alignItems: 'center',
          gap: 10,
          backgroundColor: colors.white,
          borderBottomWidth: 1,
          borderBottomColor: colors.gray100,
        }}
      >
        <Pressable
          onPress={() => router.back()}
          style={{
            width: 34,
            height: 34,
            borderRadius: 8,
            borderWidth: 1.5,
            borderColor: colors.gray200,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Ionicons name="chevron-back" size={18} color={colors.gray600} />
        </Pressable>
        <Text style={{ fontSize: 16, fontWeight: '700', color: colors.black, flex: 1 }}>
          Weekly and Time Log Summary
        </Text>
      </View>

      <OfflineNotice feature="weeklyActivityLog" show={isOffline} />

      <Banner variant="info">
        One sheet per Period Covered, each holding the activity rows from the official MDC form. The supervisor signs
        the printed copy by hand — the app never stores a signature.
      </Banner>

      <View style={{ paddingHorizontal: 20, marginTop: 16 }}>
        {showForm ? null : (
          <Button
            label="New Log Sheet"
            icon="add"
            fullWidth
            disabled={isOffline}
            onPress={() => setShowForm(true)}
          />
        )}
      </View>

      {showForm ? (
        <Card title="New Log Sheet">
          <Field label="Period Covered — Start (YYYY-MM-DD)" value={weekStart} onChange={setWeekStart} />
          <Field label="Period Covered — End (YYYY-MM-DD)" value={weekEnd} onChange={setWeekEnd} />
          <Field label="Area Assigned (optional)" value={area} onChange={setArea} />
          <View style={{ flexDirection: 'row', gap: 10, marginTop: 14 }}>
            <Button label="Cancel" variant="secondary" fullWidth onPress={() => setShowForm(false)} />
            <Button label="Create" fullWidth loading={creating} disabled={creating} onPress={onCreate} />
          </View>
        </Card>
      ) : null}

      {logs.length === 0 ? (
        <Card>
          <Text style={{ fontSize: 12.5, color: colors.gray500 }}>
            No log sheets yet. Create one for the period you are reporting on.
          </Text>
        </Card>
      ) : (
        logs.map((log) => (
          <Pressable key={log.id} onPress={() => router.push(`/weekly-activity/${log.id}`)}>
            <Card>
              <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                <View style={{ flex: 1, paddingRight: 10 }}>
                  <Text style={{ fontSize: 13.5, fontWeight: '700', color: colors.black }}>
                    {dateRangeLabel(log.week_start, log.week_end)}
                  </Text>
                  <Text style={{ fontSize: 11.5, color: colors.gray500, marginTop: 3 }}>
                    {log.area_assigned ? `${log.area_assigned} · ` : ''}
                    {log.no_of_hours ? `${log.no_of_hours} hrs` : 'Hours not set'}
                  </Text>
                </View>
                <Ionicons name="chevron-forward" size={18} color={colors.gray400} />
              </View>
            </Card>
          </Pressable>
        ))
      )}
    </ScrollView>
  );
}

function Field({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <View style={{ marginBottom: 12 }}>
      <Text style={{ fontSize: 11, fontWeight: '600', color: colors.gray600, marginBottom: 6 }}>{label}</Text>
      <TextInput
        value={value}
        onChangeText={onChange}
        placeholder={label}
        placeholderTextColor={colors.gray400}
        autoCapitalize="none"
        style={{
          borderWidth: 1.5,
          borderColor: colors.gray200,
          borderRadius: 10,
          paddingHorizontal: 12,
          paddingVertical: 10,
          fontSize: 13,
          color: colors.black,
        }}
      />
    </View>
  );
}
