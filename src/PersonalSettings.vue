<script>
/**
 * @copyright Copyright (c) 2022, 2023 Claus-Justus Heine <himself@claus-justus-heine.de>
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
</script>
<template>
  <SettingsSection :class="[...cloudVersionClasses, appName]" :title="t(appName, 'Embedded RoundCube, Personal Settings')">
    <SettingsInputText v-model="emailAddress"
                       :label="t(appName, 'Email Login Name')"
                       :title="t(appName, 'Email user for Roundcube')"
                       :hint="emailAddressHint"
                       :disabled="emailAddressDisabled"
                       @update="saveTextInput(...arguments, 'emailAddress')"
    />
    <SettingsInputText v-model="emailPassword"
                       type="password"
                       :label="t(appName, 'Email Password')"
                       :title="t(appName, 'Email password for Roundcube')"
                       :hint="emailPasswordHint"
                       :disabled="emailPasswordDisabled"
                       @update="saveTextInput(...arguments, 'emailPassword')"
    />
  </SettingsSection>
</template>
<script>
import { appName } from './config.js'
import Vue from 'vue'
import SettingsSection from '@nextcloud/vue/dist/Components/NcSettingsSection'
import SettingsInputText from '@rotdrop/nextcloud-vue-components/lib/components/SettingsInputText'
import settingsSync from './toolkit/mixins/settings-sync'
import cloudVersionClasses from './toolkit/util/cloud-version-classes.js'

export default {
  name: 'PersonalSettings',
  components: {
    SettingsSection,
    SettingsInputText,
  },
  data() {
    return {
      loading: 0,
      cloudVersionClasses,
      emailAddress: null,
      emailPassword: null,
      emailAddressChoiceAdmin: null,
      emailDefaultDomainAdmin: null,
      fixedSingleEmailAddressAdmin: null,
      forceSSOAdmin: null
    }
  },
  mixins: [
    settingsSync,
  ],
  computed: {
    emailAddressDisabled() {
      if (this.loading > 0) {
        return true
      }
      switch (this.emailAddressChoiceAdmin) {
        case 'userIdEmail':
          return true
        case 'userPreferencesEmail':
          return true
        case 'userChosenEmail':
          return false
        case 'fixedSingleAddress':
          return true
      }
      return false
    },
    emailAddressHint() {
      switch (this.emailAddressChoiceAdmin) {
        case 'userIdEmail':
          return t(appName, 'Globally configured as USERID@{emailDefaultDomainAdmin}', this)
        case 'userPreferencesEmail':
          return t(appName, 'Globally configured as user\'s email address, see user\'s personal settings.')
        case 'fixedSingleAddress':
          return t(appName, 'Globally configured as {fixedSingleEmailAddressAdmin}', this)
        case 'userChosenEmail':
        default:
          return t(appName, 'Please specify an email address to use with RoundCube.')
      }
    },
    emailPasswordDisabled() {
      if (this.forceSSO) {
        return true
      }
      switch (this.emailAddressChoiceAdmin) {
        case 'userIdEmail':
          return false
        case 'userPreferencesEmail':
          return false
        case 'userChosenEmail':
          return false
        case 'fixedSingleAddress':
          return true
        default:
          return false
      }
    },
    emailPasswordHint() {
      if (this.emailAddressChoiceAdmin === 'fixedSingleAddress') {
        return t(appName, 'Globally configured by the administrator')
      }
      return this.forceSSOAdmin
           ? t(appName, 'Single sign-on is globally forced "on".')
           : t(appName, 'Email password for RoundCube, if needed.')
    },
  },
  watch: {},
  created() {
    this.getData()
  },
  mounted() {
  },
  methods: {
    info() {
      console.info(...arguments)
    },
    async getData() {
      // slurp in all personal settings
      ++this.loading
      this.fetchSettings('personal').finally(() => {
        console.info('THIS', this)
        --this.loading
      })
    },
    async saveTextInput(value, settingsKey, force) {
      if (this.loading > 0) {
        // avoid ping-pong by reactivity
        console.info('SKIPPING SETTINGS-SAVE DURING LOAD', settingsKey, value)
        return
      }
      this.saveConfirmedSetting(value, 'personal', settingsKey, force, this.updatePatternTestResult);
    },
    async saveSetting(setting) {
      if (this.loading > 0) {
        // avoid ping-pong by reactivity
        console.info('SKIPPING SETTINGS-SAVE DURING LOAD', setting)
        return
      }
      this.saveSimpleSetting(setting, 'personal', this.updatePatternTestResult)
    },
  },
}
</script>
<style lang="scss" scoped>
.cloud-version {
  --cloud-icon-info: var(--icon-info-dark);
  --cloud-icon-checkmark: var(--icon-checkmark-dark);
  --cloud-icon-alert: var(--icon-alert-outline-dark);
  --cloud-theme-filter: var(--background-invert-if-dark);
  &.cloud-version-major-24 {
    --cloud-icon-info: var(--icon-info-000);
    --cloud-icon-checkmark: var(--icon-checkmark-000);
    --cloud-icon-alert: var(--icon-alert-outline-000);
    --cloud-theme-filter: none;
  }
}
.settings-section {
  :deep(.app-settings-section) {
    margin-bottom: 40px;
  }
  :deep(.settings-section__title) {
    position: relative;
    padding-left:48px;
    height:32px;
    &::before {
      content: "";
      position: absolute;
      left: 0;
      top: 0;
      width: 32px;
      height: 32px;
      background-size:32px;
      background-image:url('../img/app.svg');
      background-repeat:no-repeat;
      background-origin:border-box;
      background-position:left center;
      filter: var(--cloud-theme-filter);
    }
  }
}
</style>
