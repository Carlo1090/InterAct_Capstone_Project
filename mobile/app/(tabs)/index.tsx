import { ScrollView, View, Text } from 'react-native';
import { router } from 'expo-router';
import { TopBar } from '../../src/components/TopBar';
import { Banner } from '../../src/components/Banner';
import { Button } from '../../src/components/Button';
import { OfflineNotice } from '../../src/components/OfflineNotice';
import { StatCard } from '../../src/components/StatCard';
import { Card } from '../../src/components/Card';
import { ProgressRow } from '../../src/components/ProgressRow';
import { ActivityItem } from '../../src/components/ActivityItem';
import { ErrorState, LoadingState } from '../../src/components/ErrorState';
import { useDashboard } from '../../src/hooks/useDashboard';
import { colors } from '../../src/constants/colors';

export default function Dashboard() {
  const { data, loading, error, isOffline, reload } = useDashboard();

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

      <OfflineNotice feature="dashboard" show={isOffline} />

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
          data.recent_activity.map((a, i) => <ActivityItem key={i} tone={a.tone} text={a.text} time={a.time} />)
        )}
      </Card>
      {/* "Internship Details" was removed from here — Profile's own
          Internship section already carried the same fields, so the dashboard
          was duplicating it. Profile is now the single place for them. */}
    </ScrollView>
  );
}
