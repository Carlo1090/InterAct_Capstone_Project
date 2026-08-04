import { Pressable, Text } from 'react-native';
import { colors } from '../constants/colors';

export function PrimaryButton({
  label,
  onPress,
  disabled,
}: {
  label: string;
  onPress: () => void;
  disabled?: boolean;
}) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      style={({ pressed }) => ({
        marginHorizontal: 20,
        marginTop: 16,
        backgroundColor: disabled ? colors.gray300 : pressed ? colors.blue700 : colors.blue600,
        borderRadius: 12,
        padding: 14,
        alignItems: 'center',
      })}
    >
      <Text style={{ color: 'white', fontSize: 14, fontWeight: '600' }}>{label}</Text>
    </Pressable>
  );
}
