import { ScrollView, View, Text, Pressable, ActivityIndicator } from 'react-native';
import { router } from 'expo-router';
import { TopBar } from '../../src/components/TopBar';
import { Banner } from '../../src/components/Banner';
import { StatCard } from '../../src/components/StatCard';
import { Card } from '../../src/components/Card';
import { ProgressRow } from '../../src/components/ProgressRow';
import { ActivityItem } from '../../src/components/ActivityItem';
import { useDashboard } from '../../src/hooks/useDashboard';
import { colors } from '../../src/constants/colors';

export default function Dashboard() {
  const { data, loading } = useDashboard();

  if (loading || !data) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.gray50 }}>
        <TopBar />
        <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }}>
          <ActivityIndicator color={colors.blue600} />
        </View>
      </View>
    );
  }

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
        <Pressable
          onPress={() => router.push('/write')}
          style={{ backgroundColor: colors.blue600, paddingVertical: 8, paddingHorizontal: 14, borderRadius: 8 }}
        >
          <Text style={{ color: 'white', fontSize: 12, fontWeight: '600' }}>+ Write Today</Text>
        </Pressable>
      </View>

      <Banner variant="warn">{data.missingDaysBanner}</Banner>

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
        <StatCard label="Total Entries" value={data.stats.totalEntries} sub="Submitted" accent />
        <StatCard label="Weekly Reports Approved" value={data.stats.weeklyLogsApproved} sub="By supervisor" />
        <StatCard label="Weekly Reports Pending" value={data.stats.weeklyLogsPending} sub="Awaiting review" />
        <StatCard label="Missing This Week" value={data.stats.missingThisWeek} sub="Not yet submitted" danger />
      </View>

      <Card title="Completion Progress">
        <ProgressRow name="Daily Journal Completion" pct={data.progress.dailyCompletionPct} variant="blue" />
        <ProgressRow name="Weekly Reports Approved" pct={data.progress.weeklyApprovedPct} variant="dark" />
        <ProgressRow name="OJT Duration Progress" pct={data.progress.durationPct} variant="light" />
      </Card>

      <Card title="Recent Activity">
        {data.activity.map((a) => (
          <ActivityItem key={a.id} dot={a.dot} text={a.text} time={a.time} />
        ))}
      </Card>

      <Card title={`Internship Details · ${data.internship.batch}`}>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
          <DetailBlock label="Host Company" value={data.internship.hostCompany} />
          <DetailBlock label="Supervisor" value={data.internship.supervisor} />
          <DetailBlock label="Department" value={data.internship.department} />
          <DetailBlock label="Start Date" value={data.internship.startDate} />
          <DetailBlock label="End Date" value={data.internship.endDate} />
          <DetailBlock label="Coordinator" value={data.internship.coordinator} />
        </View>
      </Card>
    </ScrollView>
  );
}

function DetailBlock({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ width: '50%', marginTop: 12 }}>
      <Text style={{ fontSize: 10, color: colors.gray400, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 3 }}>
        {label}
      </Text>
      <Text style={{ fontSize: 13, fontWeight: '600', color: colors.black }}>{value}</Text>
    </View>
  );
}
