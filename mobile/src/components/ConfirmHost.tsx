import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import {
  AccessibilityInfo,
  Animated,
  Easing,
  Modal,
  Pressable,
  Text,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../constants/colors';
import {
  ConfirmTone,
  getConfirmSnapshot,
  resolveConfirm,
  subscribeToConfirm,
} from '../services/confirm';

/**
 * Draws the one confirmation dialog. Mounted once at the app root, above the
 * navigator, so it is reachable from every screen including the Write Journal
 * modal.
 *
 * THE TONE IS THE POINT. A native Alert gives every question the same face, so
 * "Submit this entry?" and "Delete this row?" were drawn identically and the
 * destructive one relied entirely on the student reading the word. Here the
 * icon chip, the icon itself and the confirm button move together, so the
 * weight of the action is visible before a word is read.
 *
 * The confirm button is FILLED and the cancel is OUTLINED, matching `Button`'s
 * own primary/secondary rule — fill carries emphasis, and both stay visibly
 * pressable. Cancel sits on the LEFT so a destructive answer is never the one
 * closest to a right thumb's resting position.
 */
const TONE: Record<
  ConfirmTone,
  { chip: string; icon: string; fill: string; glyph: keyof typeof Ionicons.glyphMap }
> = {
  default: { chip: colors.blue50, icon: colors.blue600, fill: colors.blue600, glyph: 'help-circle' },
  danger: { chip: colors.redBg, icon: colors.redDark, fill: colors.redDark, glyph: 'alert-circle' },
  success: { chip: colors.greenBg, icon: colors.greenTx, fill: colors.blue600, glyph: 'checkmark-circle' },
  warn: { chip: colors.warnBg, icon: colors.warnTx, fill: colors.blue600, glyph: 'information-circle' },
};

export function ConfirmHost() {
  const request = useSyncExternalStore(subscribeToConfirm, getConfirmSnapshot, getConfirmSnapshot);

  // The request is held after it resolves so the dialog can animate OUT with
  // its own words still on screen. Without this the card empties a frame
  // before it disappears.
  const [shown, setShown] = useState(request);
  const [visible, setVisible] = useState(request !== null);
  const fade = useRef(new Animated.Value(0)).current;
  const pop = useRef(new Animated.Value(0.92)).current;
  const [reduceMotion, setReduceMotion] = useState(false);

  useEffect(() => {
    let alive = true;
    AccessibilityInfo.isReduceMotionEnabled().then((on) => {
      if (alive) setReduceMotion(on);
    });
    const sub = AccessibilityInfo.addEventListener('reduceMotionChanged', setReduceMotion);
    return () => {
      alive = false;
      sub.remove();
    };
  }, []);

  useEffect(() => {
    if (request) {
      setShown(request);
      setVisible(true);
      // A dialog is the one thing on screen that must be read, so it is
      // announced as well as drawn.
      AccessibilityInfo.announceForAccessibility?.(
        request.message ? request.title + '. ' + request.message : request.title
      );
      if (reduceMotion) {
        fade.setValue(1);
        pop.setValue(1);
        return;
      }
      fade.setValue(0);
      pop.setValue(0.92);
      Animated.parallel([
        Animated.timing(fade, {
          toValue: 1,
          duration: 140,
          easing: Easing.out(Easing.quad),
          useNativeDriver: true,
        }),
        Animated.spring(pop, { toValue: 1, friction: 7, tension: 140, useNativeDriver: true }),
      ]).start();
      return;
    }

    if (!visible) return;

    if (reduceMotion) {
      setVisible(false);
      return;
    }

    Animated.timing(fade, {
      toValue: 0,
      duration: 110,
      easing: Easing.in(Easing.quad),
      useNativeDriver: true,
    }).start(({ finished }) => {
      if (finished) setVisible(false);
    });
    // `visible` is deliberately not a dependency: including it would re-run
    // this effect on the very state change the exit animation causes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [request, reduceMotion, fade, pop]);

  if (!visible || !shown) return null;

  const tone = TONE[shown.tone];
  const answer = (ok: boolean) => resolveConfirm(shown.id, ok);

  return (
    <Modal
      transparent
      visible
      animationType="none"
      statusBarTranslucent
      // The Android hardware back button. Dismissing counts as declining.
      onRequestClose={() => answer(false)}
    >
      <Animated.View
        style={{
          flex: 1,
          backgroundColor: 'rgba(22, 33, 58, 0.58)',
          alignItems: 'center',
          justifyContent: 'center',
          padding: 26,
          opacity: fade,
        }}
      >
        {/* Tapping outside declines — but only where there IS something to
            decline. On a one-button acknowledgement the backdrop is inert, so
            a message that must be read cannot be lost to a stray tap. */}
        <Pressable
          style={{ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 }}
          onPress={() => {
            if (shown.cancelLabel !== null) answer(false);
          }}
          accessible={false}
        />

        <Animated.View
          accessibilityViewIsModal
          accessibilityRole="alert"
          style={{
            width: '100%',
            maxWidth: 360,
            backgroundColor: colors.white,
            borderRadius: 18,
            padding: 22,
            transform: [{ scale: pop }],
            shadowColor: '#000',
            shadowOpacity: 0.28,
            shadowRadius: 22,
            shadowOffset: { width: 0, height: 10 },
            elevation: 16,
          }}
        >
          <View
            style={{
              width: 44,
              height: 44,
              borderRadius: 13,
              backgroundColor: tone.chip,
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <Ionicons name={tone.glyph} size={23} color={tone.icon} />
          </View>

          <Text
            style={{
              fontSize: 16.5,
              fontWeight: '700',
              color: colors.black,
              marginTop: 15,
              lineHeight: 23,
            }}
          >
            {shown.title}
          </Text>

          {shown.message ? (
            <Text style={{ fontSize: 13.5, lineHeight: 20, color: colors.gray600, marginTop: 7 }}>
              {shown.message}
            </Text>
          ) : null}

          <View style={{ flexDirection: 'row', gap: 10, marginTop: 22 }}>
            {shown.cancelLabel !== null ? (
              <Pressable
                onPress={() => answer(false)}
                accessibilityRole="button"
                style={({ pressed }) => ({
                  flex: 1,
                  paddingVertical: 13,
                  borderRadius: 10,
                  borderWidth: 1.5,
                  borderColor: colors.blue600,
                  alignItems: 'center',
                  opacity: pressed ? 0.85 : 1,
                })}
              >
                <Text style={{ fontSize: 14, fontWeight: '600', color: colors.blue600 }}>
                  {shown.cancelLabel}
                </Text>
              </Pressable>
            ) : null}

            <Pressable
              onPress={() => answer(true)}
              accessibilityRole="button"
              style={({ pressed }) => ({
                flex: 1,
                paddingVertical: 13,
                borderRadius: 10,
                backgroundColor: tone.fill,
                alignItems: 'center',
                opacity: pressed ? 0.88 : 1,
              })}
            >
              <Text style={{ fontSize: 14, fontWeight: '600', color: colors.white }}>
                {shown.confirmLabel}
              </Text>
            </Pressable>
          </View>
        </Animated.View>
      </Animated.View>
    </Modal>
  );
}
