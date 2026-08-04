import { useState } from 'react';
import { ScrollView, View, Text, Pressable, Alert } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../src/components/Banner';
import { Card } from '../src/components/Card';
import { PrimaryButton } from '../src/components/PrimaryButton';
import { mockReportPreview } from '../src/services/mock/studentInfo';
import { api } from '../src/services/api';
import { endpoints } from '../src/services/endpoints';
import { colors } from '../src/constants/colors';

const reportTypes = ['Full Journal Report (All Entries)', 'Weekly Summary Report', 'Missing Entries Report'];
const formats = ['PDF Document', 'Word Document'];

export default function Reports() {
  const [reportType, setReportType] = useState(reportTypes[0]);
  const [format, setFormat] = useState(formats[0]);
  const [generating, setGenerating] = useState(false);
  const p = mockReportPreview;

  async function generate() {
    setGenerating(true);
    try {
      await api.post(endpoints.reportExport, { reportType, format });
      Alert.alert('Report Generated', `Your ${format.toLowerCase()} has been generated and is ready to download.`);
    } catch {
      // Backend not reachable yet — be honest about that instead of a silent no-op.
      Alert.alert(
        'Report Not Available Yet',
        'Report generation requires a connection to the InternTrack server, which isn\'t reachable right now. This will work once the backend is live.'
      );
    } finally {
      setGenerating(false);
    }
  }

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={{ paddingBottom: 24 }}>
      <View style={{ paddingTop: 50, paddingHorizontal: 20, flexDirection: 'row', alignItems: 'center', gap: 12 }}>
        <Pressable onPress={() => router.back()}>
          <Ionicons name="chevron-back" size={22} color={colors.black} />
        </Pressable>
        <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black }}>Generate Report</Text>
      </View>

      <View
        style={{
          marginHorizontal: 20,
          marginTop: 16,
          backgroundColor: colors.white,
          borderRadius: 14,
          borderWidth: 1,
          borderColor: colors.gray200,
          padding: 20,
          alignItems: 'center',
        }}
      >
        <Text style={{ fontSize: 14, fontWeight: '700', color: colors.black }}>{p.school}</Text>
        <Text style={{ fontSize: 11, color: colors.gray400 }}>{p.address}</Text>
        <Text style={{ fontSize: 12, fontWeight: '700', color: colors.blue600, marginTop: 10 }}>{p.label}</Text>
        <Text style={{ fontSize: 11, color: colors.gray600, textAlign: 'center' }}>
          {p.batchLine}
          {'\n'}
          {p.dateRange}
        </Text>
        <View style={{ height: 1, backgroundColor: colors.gray100, width: '100%', marginVertical: 14 }} />
        <View style={{ width: '100%' }}>
          <Text style={{ fontSize: 12, color: colors.gray600, marginBottom: 6 }}>
            Student: <Text style={{ fontWeight: '700', color: colors.black }}>{p.student}</Text>
          </Text>
          <Text style={{ fontSize: 11, color: colors.gray400 }}>{p.companyLine}</Text>
        </View>
        <View style={{ height: 1, backgroundColor: colors.gray100, width: '100%', marginVertical: 14 }} />
        <ReportRow label="Total Entries" value={String(p.totalEntries)} />
        <ReportRow label="Weekly Reports Approved" value={String(p.weeklyLogsApproved)} />
        <ReportRow label="Est. Pages" value={p.estPages} />
      </View>

      <Card title="Report Parameters">
        <SelectField label="Report Type" value={reportType} options={reportTypes} onSelect={setReportType} />
        <SelectField label="Output Format" value={format} options={formats} onSelect={setFormat} last />
      </Card>

      <PrimaryButton
        label={generating ? 'Generating…' : '↓ Generate & Download PDF'}
        onPress={generate}
        disabled={generating}
      />

      <Banner variant="info">
        Only submitted entries are included in generated reports.
      </Banner>
    </ScrollView>
  );
}

function ReportRow({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', width: '100%', marginVertical: 4 }}>
      <Text style={{ fontSize: 12, color: colors.gray600 }}>{label}:</Text>
      <Text style={{ fontSize: 12, fontWeight: '600', color: colors.black }}>{value}</Text>
    </View>
  );
}

function SelectField({
  label,
  value,
  options,
  onSelect,
  last,
}: {
  label: string;
  value: string;
  options: string[];
  onSelect: (v: string) => void;
  last?: boolean;
}) {
  // Simple in-place option cycler — swap for a proper picker/modal component
  // (e.g. @react-native-picker/picker) once you want native picker UI.
  return (
    <View style={{ marginBottom: last ? 0 : 14 }}>
      <Text style={{ fontSize: 10, color: colors.gray400, textTransform: 'uppercase', marginBottom: 6 }}>{label}</Text>
      <Pressable
        onPress={() => {
          const i = options.indexOf(value);
          onSelect(options[(i + 1) % options.length]);
        }}
        style={{
          flexDirection: 'row',
          justifyContent: 'space-between',
          alignItems: 'center',
          borderWidth: 1.5,
          borderColor: colors.gray200,
          borderRadius: 10,
          padding: 12,
          backgroundColor: colors.white,
        }}
      >
        <Text style={{ fontSize: 13, color: colors.black }}>{value}</Text>
        <Text style={{ color: colors.gray400 }}>▾</Text>
      </Pressable>
    </View>
  );
}
