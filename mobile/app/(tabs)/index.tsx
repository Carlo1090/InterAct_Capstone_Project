import { ScrollView, View, Text } from 'react-native';
import { router } from 'expo-router';
import { TopBar } from '../../src/components/TopBar';
import { Banner } from '../../src/components/Banner';
import { Button } from '../../src/components/Button';
import { OfflineNotice } from '../../src/components/OfflineNotice';
import { StatCard } from '../../src/components/StatCard';
import { Card } from '../../src/components/Card';
import { ProgressRow } from '../../src/components/ProgressRow';
import { ActivityRow } from '../../src/components/ActivityRow';
import { ErrorState, LoadingState } from '../../src/components/ErrorState';
import { useDashboard } from '../../src/hooks/useDashboard';
import { useCurrentUser } from '../../src/hooks/useCurrentUser';
import { colors } from '../../src/constants/colors';

export default function Dashboard() {
  const { data, loading, error, isOffline, reload } = useDashboard();
  // Only to strip the student's own name off each row's detail line, exactly
  // as the Activity Log does. Free — the user store is already in memory.
  const { user } = useCurrentUser();

  if (loading && !data) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.gray50 }}>
        <TopBar />
        <LoadingState />
      </View>
    );
  }

  if (error && !data) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.gray50 }}>
        <TopBar />
        <ErrorState message={error.message} onRetry={reload} />
      </View>
    );
  }

  if (!data) return null;

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={{ paddingBottom: 24 }}>
      <TopBar />
      <View
        style={{
          flexDirection: 'row',
          justifyContent: 'space-between',
          alignItems: 'center',
          marginHorizontal: 20,
          marginTop: 14,
        }}
      >
        <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black }}>Dashboard</Text>
        <Button label="Write Today" icon="add" size="sm" onPress={() => router.push('/write')} />
      </View>

      <OfflineNotice feature="dashboard" show={isOffline} error={error} />

      {data.stats.missing_this_week > 0 ? (
        <Banner variant="warn">
          You have {data.stats.missing_this_week} missing {data.stats.missing_this_week === 1 ? 'entry' : 'entries'}{' '}
          this week. Journals compile every Monday at 12:00 AM.
        </Banner>
      ) : null}

      <View
        style={{
          flexDirection: 'row',
          flexWrap: 'wrap',
          justifyContent: 'space-between',
          gap: 10,
          marginHorizontal: 20,
          marginTop: 16,
        }}
      >
        {/* Total Entries and Weekly Reports Approved were removed at the
            project owner's request — the two that remain are the ones that
            need acting on. Approved counts are still visible on the Weekly
            tab, and the approval RATE is still in Completion Progress. */}
        <StatCard label="Weekly Reports Pending" value={data.stats.weekly_logs_pending} sub="Awaiting review" />
        <StatCard label="Missing This Week" value={data.stats.missing_this_week} sub="Not yet submitted" danger />
      </View>

      <Card title="Completion Progress">
        <ProgressRow name="Weekly Reports Approved" pct={data.progress.weekly_reports_approved_percent} variant="dark" />
        <ProgressRow name="OJT Duration Progress" pct={data.progress.ojt_duration_percent} variant="light" />
      </Card>

      <Card title="Recent Activity">
        {data.recent_activity.length === 0 ? (
          <Text style={{ fontSize: 12, color: colors.gray400 }}>No recent activity yet.</Text>
        ) : (
          <>
            {/* The SAME rows the Activity Log renders, through the same
                component — plain-language title, category icon and colour,
                the detail line with the student's own name trimmed, relative
                time and exact clock time. This card used to print the raw
                audit description against a bare coloured dot, so the two
                screens described one event two different ways. */}
            {data.recent_activity.map((a) => (
              <ActivityRow key={a.id} item={a} userName={user?.name} variant="plain" />
            ))}
            <Button
              label="View full activity log"
              variant="secondary"
              icon="time-outline"
              onPress={() => router.push('/activity-log')}
              style={{ marginTop: 12 }}
            />
          </>
        )}
      </Card>
      {/* "Internship Details" was removed from here — Profile's own
          Internship section already carried the same fields, so the dashboard
          was duplicating it. Profile is now the single place for them. */}
    </ScrollView>
  );
}
