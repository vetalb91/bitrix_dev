<style>
html {
  scroll-behavior: smooth;
}
</style>
<template>
  <div class="grid">
    <div class="grid-tr">
      <div class="grid-th">
        <img src="/new_logo.png">
      </div>
      <div class="grid-th" v-for="(column, key) in table.column" :key="key">
        <div class="item">
          {{ column.title }}
        </div>
        <div class="sub-item">
          <span v-if="column.options.max !== undefined && column.data_type === 'call_minutes'">{{ 'норма минут: ' + column.options.max }}</span>
          <span v-if="column.options.max !== undefined && column.data_type === 'call_out_count'">{{ 'норма звонков: ' + column.options.max }}</span>
          <span v-if="column.options.max !== undefined && column.data_type !== 'call_out_count' && column.data_type !== 'call_minutes'">{{ 'норма: ' + column.options.max }}</span>
          <span v-if="column.period !== undefined">{{ getPeriodSubstring(column.period) }}</span>
        </div>
      </div>
    </div>
    <div class="grid-tr" v-for="(name, id) in table.user_list" :key="id">
      <div class="grid-td">
        <div class="item">{{ name }}</div>
      </div>
      <div class="grid-td" v-for="column in table.column" :key="column.id + '_' + types_for_id[table_id][column.id]">
        <div class="item">
          <component :is="types_for_id[table_id][column.id]" :value="table.data[id] === undefined ? undefined : table.data[id][column.id]" :data_type="column.data_type" :data="column"></component>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import Speedo from "@/components/types/Speedo";
import Number from "@/components/types/Number";
import NumberOfDelimited from "@/components/types/NumberOfDelimited";
import SpeedoNumber from "@/components/types/SpeedoNumber";
import axios from "axios";

export default {
  name: 'Dashboard',
  props: {
    table_id: Number,
    table: Object
  },
  data() {
    return {
      types: {
        'number/number': {},
        'number': {},
        'speedo': {
          'speedo_number': 'table',
          'speedo': 'chart-bar'
        },
      }
    }
  },
  methods: {
    saveTypeForId(key, id, type) {
      this.$store.dispatch('SAVE_TYPE_FOR_ID', {
        table_id: key,
        id: id,
        type: type
      });
    },
    getPeriodSubstring(periods) {
      let translations = {
        'day': 'Сегодня',
        'week': 'Неделя',
        'month': 'Месяц'
      }
      let newPeriods = [];

      periods.map((periodName) => {
        if (translations[periodName]) {
          newPeriods.push(translations[periodName])
        }
      })

      return newPeriods.join('/')
    }
  },
  computed: {
    types_for_id() {
      return this.$store.getters.TYPES_FOR_ID;
    },
  },
  mounted() {
    let scales = document.getElementsByClassName('scale');
    for (let i = 0; i < scales.length; i++) {
      let parent = scales[i].parentElement.parentElement;
      parent.classList.add('flex-center');
      parent.style.cssText = 'margin-top: calc(0px * 0.7);';
    }
  },
  beforeCreate() {
    this.$store.dispatch('UPDATE_TYPE_FOR_ID');
  },
  components: {
    Speedo, Number, NumberOfDelimited, SpeedoNumber
  }
}

var height = 0;

var res = document.addEventListener('scroll', function() {

  height = window.scrollY;

});


setInterval(() => {
  height = height + document.documentElement.clientHeight - 150;
  window.scrollTo({
    top: height,
    behavior: "smooth"
  });
  var totalHeight = document.documentElement.scrollHeight;
//  console.log(height, totalHeight)
  if(height === totalHeight || height > totalHeight){
    height = 0;
    window.scrollTo({
      top: 0,
      behavior: "smooth"
    })
  }



}, 11000);
</script>

