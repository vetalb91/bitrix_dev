<template>
  <div>
    <div class='scale'></div>
    <div class="scale-value">
      {{ this.getValue(this.value) }}
    </div>
    <div class="scale-icon" v-if="this.data_type !== 'call_minutes'">
      <img src="@/assets/images/phone.png">
    </div>
    <div class="scale-measure" v-if="this.data_type === 'call_minutes'">
      мин
    </div>
  </div>
  <!--        <figure class="highcharts-figure">-->
  <!--                <highcharts :options="Highcharts.merge(chartOptions, data_value)" style="width:200px;height:100px;"></highcharts>-->
  <!--        </figure>-->

</template>

<script>
export default {
  name: "Speedo",
  mounted() {
    this.$el.getElementsByClassName('scale')[0].style.setProperty('--p', -this.getScaleData(this.value, this.data.options.max))
  },
  props: {
    value: {
      type: Number,
      default() {
        return 0;
      }
    },
    data: {
      type: Object,
      default() {
        return {
          options: {
            max: 0
          }
        }
      }
    },
    data_type: String
  },
  components: {},
  methods: {
    getScaleData(value, max) {
      value = this.getValue(value)
      max = max === undefined ? 100 : max;
      let percentage = Math.floor((value / Number(max)) * 100);
      return 40 - (40 * (percentage / 100)) + 2;
    },
    getValue(value) {
      if (this.data_type === 'call_minutes') {
        return Math.ceil(value / 60);
      }
      if (this.data_type === 'call_out_minutes') {
        return Math.ceil(value / 60);
      }
      if (this.data_type === 'call_in_minutes') {
        return Math.ceil(value / 60);
      }

      return value;
    }
  }
}
</script>